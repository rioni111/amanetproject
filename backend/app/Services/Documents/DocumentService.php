<?php

namespace App\Services\Documents;

use App\Enums\ActivityEventType;
use App\Enums\DocumentCategory;
use App\Enums\DocumentOrigin;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\SigningCancellationReason;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Signing\SigningLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Document library operations.
 *
 * Upload workflow (files cannot take part in a database transaction, so the order matters):
 *   1. the Form Request has validated the file (PdfInspector); its SHA-256 is computed here
 *   2. the file is copied to a private staging path and verified (size + SHA-256)
 *   3. it is moved to a fresh, never-used final path
 *   4. ONE transaction: lock matter (and document), duplicate check, version number, rows, history
 *   5. if step 4 fails, the NEW final file is deleted; previously committed files are never touched
 *   6. the staging file is always removed
 * A response is therefore only successful when both the database rows and the file exist.
 */
class DocumentService
{
    public const STALE_MESSAGE = 'This document was changed by someone else after you opened it. Reload to see the latest version.';

    private readonly SigningLifecycle $signing;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly DocumentStorage $storage,
        ?SigningLifecycle $signing = null,
    ) {
        $this->signing = $signing ?? app(SigningLifecycle::class);
    }

    /** @param array<string, mixed> $data validated: title?, description?, category */
    public function create(Matter $matter, UploadedFile $file, array $data, User $actor): Document
    {
        $documentUuid = Str::uuid()->toString();

        return $this->persistUpload($file, $matter->id, $documentUuid, function (string $finalPath, string $sha256, string $versionUuid) use ($matter, $data, $actor, $file, $documentUuid) {
            $matter = $this->lockWritableMatter($matter);
            $this->rejectDuplicateInMatter($matter, $sha256);

            $filename = $this->safeFilename($file->getClientOriginalName());
            $document = $this->insertDocument($matter, $documentUuid, $data['title'] ?? $this->titleFromFilename($filename),
                $data['description'] ?? null, DocumentCategory::from($data['category']), DocumentOrigin::Admin, $actor);
            $version = $this->insertVersion($document, 1, $versionUuid, $filename, $finalPath, $file->getSize(), $sha256, DocumentOrigin::Admin, $actor->id, $actor->name);
            $document->forceFill(['current_version_id' => $version->id, 'latest_version_number' => 1])->saveQuietly();

            $this->activity->record($matter, ActivityEventType::DocumentUploaded,
                "Document uploaded: {$document->title} (v1).", $actor, $document,
                $this->versionMetadata($document, $version));
            $this->touchMatter($matter);

            return $document;
        });
    }

    /** Adds the next version. Concurrent uploads serialise on the document row lock. */
    public function addVersion(Matter $matter, Document $document, UploadedFile $file, User $actor, ?int $lockVersion = null): DocumentVersion
    {
        return $this->persistUpload($file, $matter->id, $document->uuid, function (string $finalPath, string $sha256, string $versionUuid) use ($matter, $document, $file, $actor, $lockVersion) {
            $matter = $this->lockWritableMatter($matter);
            $document = $this->lockDocument($matter, $document);
            $document->assertLockVersion($lockVersion, self::STALE_MESSAGE);
            $document->assertNotArchived();
            $this->rejectSameAsCurrent($document, $sha256);
            // A pending signing request is pinned to the old version: it must not sign a superseded file.
            $this->signing->cancelActiveForDocument($matter, $document, SigningCancellationReason::SourceSuperseded, $actor);

            $version = $this->appendVersion($document, $versionUuid, $this->safeFilename($file->getClientOriginalName()),
                $finalPath, $file->getSize(), $sha256, DocumentOrigin::Admin, $actor->id, $actor->name);

            $this->activity->record($matter, ActivityEventType::DocumentVersionAdded,
                "New version of {$document->title}: {$version->label()}.", $actor, $version,
                $this->versionMetadata($document, $version));
            $this->touchMatter($matter);

            return $version;
        });
    }

    /**
     * The shared, file-safe upload pipeline (also used by client submissions in Phase 4):
     * hash → private staging copy verified byte-for-byte → move to a fresh final path → run
     * `$transaction(finalPath, sha256, versionUuid)` inside ONE database transaction. If anything in
     * the transaction fails, this request's new file is deleted; staging files are always removed.
     *
     * @template T
     *
     * @param  callable(string $finalPath, string $sha256, string $versionUuid): T  $transaction
     * @return T
     */
    public function persistUpload(UploadedFile $file, int $matterId, string $documentUuid, callable $transaction): mixed
    {
        $sha256 = hash_file('sha256', $file->getRealPath());

        return $this->persistStaged($this->storage->stage($file, $sha256), $sha256, $matterId, $documentUuid, $transaction);
    }

    /**
     * persistUpload() for a file produced on the server (Phase 5: a generated signed PDF in a temp directory).
     *
     * @template T
     *
     * @param  callable(string $finalPath, string $sha256, string $versionUuid): T  $transaction
     * @return T
     */
    public function persistLocalFile(string $localPath, int $size, int $matterId, string $documentUuid, callable $transaction): mixed
    {
        $sha256 = hash_file('sha256', $localPath);

        return $this->persistStaged($this->storage->stageLocalFile($localPath, $size, $sha256), $sha256, $matterId, $documentUuid, $transaction);
    }

    /**
     * @template T
     *
     * @param  callable(string $finalPath, string $sha256, string $versionUuid): T  $transaction
     * @return T
     */
    private function persistStaged(string $stagingPath, string $sha256, int $matterId, string $documentUuid, callable $transaction): mixed
    {
        $finalPath = null;
        try {
            $versionUuid = Str::uuid()->toString();
            $finalPath = $this->storage->finalize($stagingPath, $matterId, $documentUuid, $versionUuid);

            return DB::transaction(fn () => $transaction($finalPath, $sha256, $versionUuid));
        } catch (Throwable $e) {
            $this->storage->discard($finalPath); // only this request's new file — committed files are never touched
            throw $e;
        } finally {
            $this->storage->discard($stagingPath); // already moved on success; removed on failure
        }
    }

    /** Inserts a document row without a version (the caller adds v1 in the same transaction). */
    public function insertDocument(Matter $matter, string $uuid, string $title, ?string $description, DocumentCategory $category, DocumentOrigin $origin, ?User $uploader): Document
    {
        $document = new Document;
        $document->forceFill([
            'uuid' => $uuid,
            'matter_id' => $matter->id,
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'status' => DocumentStatus::Active,
            'origin' => $origin, // server-assigned
            'uploaded_by' => $uploader?->id,
        ])->save();

        return $document;
    }

    /** Adds the next version to a document that the caller has locked (SELECT … FOR UPDATE). */
    public function appendVersion(Document $document, string $uuid, string $filename, string $path, int $size, string $sha256, DocumentOrigin $origin, ?int $uploaderId, string $uploaderName, DocumentVersionKind $kind = DocumentVersionKind::Upload): DocumentVersion
    {
        $number = $document->latest_version_number + 1;
        $version = $this->insertVersion($document, $number, $uuid, $filename, $path, $size, $sha256, $origin, $uploaderId, $uploaderName, $kind);
        $document->forceFill(['current_version_id' => $version->id, 'latest_version_number' => $number])->save();

        return $version;
    }

    /** A new version must differ from the current one. */
    public function rejectSameAsCurrent(Document $document, string $sha256, string $message = 'This PDF is identical to the current version (:version). Upload the revised file.'): void
    {
        $current = $document->currentVersion;
        if ($current !== null && hash_equals($current->sha256_checksum, $sha256)) {
            throw ValidationException::withMessages(['file' => [str_replace(':version', $current->label(), $message)]]);
        }
    }

    /** @param array<string, mixed> $data validated: title, description, category, lock_version */
    public function update(Matter $matter, Document $document, array $data, User $actor): Document
    {
        return DB::transaction(function () use ($matter, $document, $data, $actor) {
            $matter = $this->lockWritableMatter($matter);
            $document = $this->lockDocument($matter, $document);
            $document->assertLockVersion($data['lock_version'] ?? null, self::STALE_MESSAGE);
            $document->assertNotArchived();

            $before = ['title' => $document->title, 'category' => $document->category->value];
            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $document->{$field} = $data[$field];
                }
            }
            if (array_key_exists('category', $data)) {
                $document->category = DocumentCategory::from($data['category']);
            }
            if (! $document->isDirty()) {
                return $document;
            }
            $descriptionChanged = $document->isDirty('description');
            $document->save();

            $changes = [];
            foreach (['title' => $document->title, 'category' => $document->category->value] as $field => $after) {
                if ($before[$field] !== $after) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after];
                }
            }
            if ($descriptionChanged) {
                $changes['description'] = ['changed' => true];
            }
            $this->activity->record($matter, ActivityEventType::DocumentUpdated,
                "Document details updated: {$document->title}.", $actor, $document,
                ['document_id' => $document->id, 'changes' => $changes]);
            $this->touchMatter($matter);

            return $document;
        });
    }

    public function archive(Matter $matter, Document $document, User $actor, ?int $lockVersion = null): Document
    {
        return $this->setStatus($matter, $document, $actor, $lockVersion, DocumentStatus::Archived);
    }

    public function restore(Matter $matter, Document $document, User $actor, ?int $lockVersion = null): Document
    {
        return $this->setStatus($matter, $document, $actor, $lockVersion, DocumentStatus::Active);
    }

    /**
     * Audit policy: every download of a file (explicit "save a copy") is recorded in the matter's
     * append-only history; inline previews inside the workspace are not, to keep the timeline useful.
     */
    public function recordDownload(Matter $matter, Document $document, DocumentVersion $version, User $actor): void
    {
        $this->activity->record($matter, ActivityEventType::DocumentDownloaded,
            "Document downloaded: {$document->title} ({$version->label()}).", $actor, $version,
            ['document_id' => $document->id, 'version_id' => $version->id, 'version_number' => $version->version_number]);
    }

    // ------------------------------------------------------------------ internals

    private function insertVersion(Document $document, int $number, string $uuid, string $filename, string $path, int $size, string $sha256, DocumentOrigin $origin, ?int $uploaderId, string $uploaderName, DocumentVersionKind $kind = DocumentVersionKind::Upload): DocumentVersion
    {
        $version = new DocumentVersion;
        $version->forceFill([
            'uuid' => $uuid,
            'document_id' => $document->id,
            'version_number' => $number,
            'kind' => $kind,
            'origin' => $origin,
            'original_filename' => $filename,
            'storage_disk' => $this->storage->diskName(),
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'sha256_checksum' => $sha256,
            'uploaded_by' => $uploaderId,
            'uploaded_by_name' => $uploaderName,
        ])->save();

        return $version;
    }

    private function setStatus(Matter $matter, Document $document, User $actor, ?int $lockVersion, DocumentStatus $target): Document
    {
        return DB::transaction(function () use ($matter, $document, $actor, $lockVersion, $target) {
            $matter = $this->lockWritableMatter($matter);
            $document = $this->lockDocument($matter, $document);
            $document->assertLockVersion($lockVersion, self::STALE_MESSAGE);
            if ($document->status === $target) {
                throw ValidationException::withMessages(['status' => [$target === DocumentStatus::Archived
                    ? 'This document is already archived.' : 'This document is not archived.']]);
            }
            if ($target === DocumentStatus::Archived) {
                // Archived documents are read-only: unfinished signing becomes impossible (links revoked too).
                $this->signing->cancelActiveForDocument($matter, $document, SigningCancellationReason::DocumentArchived, $actor);
            }
            $document->forceFill([
                'status' => $target,
                'archived_at' => $target === DocumentStatus::Archived ? Carbon::now() : null,
            ])->save();

            $archived = $target === DocumentStatus::Archived;
            $this->activity->record($matter, $archived ? ActivityEventType::DocumentArchived : ActivityEventType::DocumentRestored,
                ($archived ? 'Document archived: ' : 'Document restored: ')."{$document->title}. All versions are kept.", $actor, $document,
                ['document_id' => $document->id]);
            $this->touchMatter($matter);

            return $document;
        });
    }

    /** Matter row lock first (same order everywhere), archived matters are read-only. */
    private function lockWritableMatter(Matter $matter): Matter
    {
        $locked = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        $locked->assertNotArchived();

        return $locked;
    }

    private function lockDocument(Matter $matter, Document $document): Document
    {
        return Document::query()->whereBelongsTo($matter)->with('currentVersion')->lockForUpdate()->findOrFail($document->getKey());
    }

    /**
     * Duplicate policy for NEW documents: the same file (SHA-256) already stored anywhere in this
     * matter is rejected with a pointer to it — this also stops double submissions. Runs under the
     * matter row lock, so two simultaneous identical uploads cannot both pass.
     */
    private function rejectDuplicateInMatter(Matter $matter, string $sha256): void
    {
        $existing = DocumentVersion::query()
            ->select('document_versions.*')
            ->join('documents', 'documents.id', '=', 'document_versions.document_id')
            ->where('documents.matter_id', $matter->id)
            ->where('document_versions.sha256_checksum', $sha256)
            ->with('document:id,title')
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'file' => ["This PDF is already stored in this matter as \"{$existing->document->title}\" ({$existing->label()})."],
            ]);
        }
    }

    /** @return array<string, mixed> non-confidential facts only (no paths, no content) */
    public function versionMetadata(Document $document, DocumentVersion $version): array
    {
        return [
            'document_id' => $document->id,
            'version_id' => $version->id,
            'version_number' => $version->version_number,
            'category' => $document->category->value,
            'filename' => $version->original_filename,
            'file_size' => $version->file_size,
            'sha256' => $version->sha256_checksum,
        ];
    }

    /**
     * The client-supplied name is metadata only: path components, control characters and header
     * delimiters are removed, the length is capped and the extension forced to .pdf.
     */
    public function safeFilename(?string $name): string
    {
        $base = basename(str_replace('\\', '/', (string) $name));
        $base = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '', " .\t");
        $stem = preg_replace('/\.pdf$/i', '', $base) ?? '';
        $stem = Str::limit($stem === '' ? 'document' : $stem, 180, '');

        return $stem.'.pdf';
    }

    private function titleFromFilename(string $filename): string
    {
        return Str::limit(trim(str_replace(['_', '-'], ' ', preg_replace('/\.pdf$/i', '', $filename) ?? '')), 255, '') ?: 'Untitled document';
    }

    public function touchMatter(Matter $matter): void
    {
        Matter::query()->whereKey($matter->getKey())->update(['updated_at' => Carbon::now()]);
    }
}
