<?php

namespace App\Services\Signing;

use App\Enums\ActivityEventType;
use App\Enums\DocumentOrigin;
use App\Enums\DocumentVersionKind;
use App\Enums\NotificationKind;
use App\Enums\SignerType;
use App\Enums\SigningStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SignatureRecord;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Communications\AdminNotifier;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use App\Support\SigningConsent;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Signing progress and completion.
 *
 * Completion (exactly once per request):
 *   1. validate input (appearance, placement, consent, name) and the request state
 *   2. verify the pinned source file exists and matches its stored SHA-256
 *   3. generate the signed PDF in a temp file and verify it (PdfSigningService)
 *   4. DocumentService::persistLocalFile — private staging, SHA-256 check, unique final path — then ONE transaction:
 *      lock matter → document → request (→ link); re-check archive state, the source is still current, the
 *      request is still signable and (client) the link is still usable; append the immutable signed version
 *      (kind = signed, becomes current), insert the signature record, mark the request signed, consume the link,
 *      write signing.completed + document.signed
 *   5. any failure: rollback, delete only this attempt's new file, temp file removed; nothing reports success.
 * A second attempt for the same request finds it signed (or the link consumed) under the lock and is refused.
 */
class SigningService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly PdfSigningService $engine,
        private readonly DocumentService $documents,
        private readonly DocumentStorage $storage,
        private readonly SigningRequestService $requests,
        private readonly PublicSigningLinkResolver $resolver,
    ) {}

    // ------------------------------------------------------------------ client progress

    /** The signer's browser rendered the designated PDF. First occurrence only; never moves progress backwards. */
    public function recordViewed(SecureAccessLink $link): SigningRequest
    {
        return $this->progress($link, function (Matter $matter, SigningRequest $request) {
            if ($request->status->rank() >= SigningStatus::Viewed->rank()) {
                return $request; // idempotent: already viewed / started
            }
            $from = $request->status;
            $request->forceFill(['status' => SigningStatus::Viewed, 'viewed_at' => Carbon::now()])->save();
            $this->activity->recordClient($matter, ActivityEventType::SigningViewed,
                "Document opened for signing by {$request->signer_name} through the secure link.", $request->signer_name, $request->id, $request,
                ['signing_request_id' => $request->id, 'from' => $from->value, 'to' => SigningStatus::Viewed->value, 'source' => 'secure_link']);

            return $request;
        });
    }

    /** The signer explicitly began the signing step (after viewing the document). */
    public function recordStarted(SecureAccessLink $link): SigningRequest
    {
        return $this->progress($link, function (Matter $matter, SigningRequest $request) {
            if ($request->status === SigningStatus::Started) {
                return $request; // idempotent
            }
            if ($request->status !== SigningStatus::Viewed) {
                throw new ConflictHttpException('Open and review the document before starting to sign.');
            }
            $request->forceFill(['status' => SigningStatus::Started, 'started_at' => Carbon::now()])->save();
            $this->activity->recordClient($matter, ActivityEventType::SigningStarted,
                "{$request->signer_name} started signing through the secure link.", $request->signer_name, $request->id, $request,
                ['signing_request_id' => $request->id, 'from' => SigningStatus::Viewed->value, 'to' => SigningStatus::Started->value, 'source' => 'secure_link']);

            return $request;
        });
    }

    private function progress(SecureAccessLink $link, Closure $apply): SigningRequest
    {
        return DB::transaction(function () use ($link, $apply) {
            $matter = Matter::query()->lockForUpdate()->findOrFail($link->matter_id);
            $request = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($link->signing_request_id);
            $link = SecureAccessLink::query()->lockForUpdate()->findOrFail($link->id);
            $this->resolver->assertUsable($link, $request, $matter);

            return $apply($matter, $request);
        });
    }

    // ------------------------------------------------------------------ completion

    /** @param array<string, mixed> $input validated signature payload */
    public function signAsClient(SecureAccessLink $link, array $input, ?string $ip, ?string $userAgent): SigningRequest
    {
        $request = SigningRequest::query()->with(['matter', 'document', 'sourceVersion'])->findOrFail($link->signing_request_id);
        if ($request->status !== SigningStatus::Started) {
            throw new ConflictHttpException('Start the signing step before signing.');
        }

        return $this->complete($request->matter, $request->document, $request->sourceVersion, $request->uuid, $request->signer_name, $input,
            SignerType::Client, $ip, $userAgent, null,
            function (Matter $matter, Document $document) use ($request, $link) {
                $locked = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($request->id);
                $lockedLink = SecureAccessLink::query()->lockForUpdate()->findOrFail($link->id);
                $this->resolver->assertUsable($lockedLink, $locked, $matter);
                if ($locked->status !== SigningStatus::Started) {
                    throw new ConflictHttpException('Start the signing step before signing.');
                }

                return [$locked, $lockedLink];
            });
    }

    /** An administrator signs an existing administrator signing request designated to them. */
    public function signAsAdmin(Matter $matter, SigningRequest $request, array $input, User $actor, ?int $lockVersion, ?string $ip, ?string $userAgent): SigningRequest
    {
        $request->loadMissing(['document', 'sourceVersion']);
        $this->assertAdminSigner($request, $actor);

        return $this->complete($matter, $request->document, $request->sourceVersion, $request->uuid, $actor->name, $input,
            SignerType::Admin, $ip, $userAgent, $actor,
            function (Matter $matter) use ($request, $actor, $lockVersion) {
                $locked = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($request->id);
                $locked->assertLockVersion($lockVersion, SigningRequestService::STALE_MESSAGE);
                $this->assertAdminSigner($locked, $actor);
                if ($locked->status !== SigningStatus::Ready) {
                    throw new ConflictHttpException($locked->status === SigningStatus::Signed
                        ? 'This document has already been signed for this request.' : 'This signing request is no longer open.');
                }

                return [$locked, null];
            });
    }

    /**
     * "Sign PDF" from a document: creates the administrator's signing request and signs in ONE transaction,
     * so an abandoned or failed attempt never leaves an open request behind.
     */
    public function signNowAsAdmin(Matter $matter, Document $document, DocumentVersion $version, array $input, User $actor, ?string $ip, ?string $userAgent): SigningRequest
    {
        $this->requests->eligibility($matter, $document, $version);
        $draft = $this->requests->newRequest($matter, $document, $version, SignerType::Admin, [], $actor);

        return $this->complete($matter, $document, $version, $draft->uuid, $actor->name, $input,
            SignerType::Admin, $ip, $userAgent, $actor,
            function (Matter $matter, Document $document) use ($draft, $version, $actor) {
                $draft->save(); // the partial unique index refuses it if another request became active meanwhile
                $this->requests->recordRequested($matter, $draft, $document, $version, $actor);

                return [$draft, null];
            });
    }

    /**
     * @param  Closure(Matter, Document): array{0: SigningRequest, 1: SecureAccessLink|null}  $lockRequest
     */
    private function complete(
        Matter $matter, Document $document, DocumentVersion $source, string $requestUuid, string $signerName, array $input,
        SignerType $signerType, ?string $ip, ?string $userAgent, ?User $actor, Closure $lockRequest,
    ): SigningRequest {
        // 1. Input.
        if (($input['consent_version'] ?? null) !== SigningConsent::VERSION || ($input['consent'] ?? false) !== true) {
            throw ValidationException::withMessages(['consent' => ['Confirm the signing statement to sign.']]);
        }
        $confirmedName = $signerType === SignerType::Admin ? $signerName : trim(preg_replace('/\s+/u', ' ', (string) ($input['confirmed_name'] ?? '')) ?? '');
        if (! self::sameName($confirmedName, $signerName)) {
            throw ValidationException::withMessages(['confirmed_name' => ["The name must match the designated signer ({$signerName}). If this is wrong, contact the firm."]]);
        }
        try {
            $appearance = ($input['method'] ?? null) === 'typed'
                ? SignatureAppearance::typed($input['typed_name'] ?? null)
                : SignatureAppearance::drawn($input['strokes'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['signature' => [$e->getMessage()]]);
        }
        if ($appearance->typedName !== null && ! self::sameName($appearance->typedName, $signerName)) {
            throw ValidationException::withMessages(['typed_name' => ["The typed signature must be the signer's name ({$signerName})."]]);
        }

        // 2. Source integrity.
        $sourcePath = $this->storage->absolutePath($source->storage_path);
        if (! is_file($sourcePath) || ! hash_equals($source->sha256_checksum, (string) hash_file('sha256', $sourcePath))) {
            throw new ConflictHttpException('The document to be signed is missing or failed its integrity check. Nothing was signed.');
        }
        try {
            $pageSizes = $this->engine->inspect($sourcePath);
        } catch (UnsupportedPdfException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }
        try {
            $placement = SignaturePlacement::fromInput($input['page'] ?? null, $input['x'] ?? null, $input['y'] ?? null, $input['width'] ?? null, $pageSizes);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['placement' => [$e->getMessage()]]);
        }

        // 3. Generate + verify.
        $signedAt = Carbon::now()->startOfSecond();
        $reference = PdfSigningService::captionReference($requestUuid);
        // tempnam() atomically reserves a unique name by creating the file. The reservation is KEPT until this
        // attempt ends: deleting it early lets a parallel signing process receive the same name and write the
        // same "<name>.pdf" (observed on Windows under concurrent load).
        $temp = tempnam(sys_get_temp_dir(), 'lexora-sign-');
        if ($temp === false) {
            throw new SigningFailedException('The signed PDF could not be prepared. The original document was not changed.');
        }
        $output = $temp.'.pdf';
        try {
            $this->engine->sign($sourcePath, $output, $pageSizes, $appearance, $placement, $signerName, $signedAt, $reference);
            $filename = $this->documents->safeFilename(preg_replace('/\.pdf$/i', '', $source->original_filename).'-signed.pdf');

            // 4. Store + commit.
            return $this->documents->persistLocalFile($output, (int) filesize($output), $matter->id, $document->uuid,
                function (string $finalPath, string $sha256, string $versionUuid) use (
                    $matter, $document, $source, $lockRequest, $appearance, $placement, $signerName, $confirmedName, $signerType, $actor, $ip, $userAgent, $signedAt, $output, $filename
                ) {
                    $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
                    $matter->assertNotArchived();
                    $document = Document::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($document->getKey());
                    $document->assertNotArchived();
                    if ($document->current_version_id !== $source->id) {
                        throw new ConflictHttpException('A newer version of this document was added, so this request can no longer be signed. Nothing was signed.');
                    }
                    [$request, $link] = $lockRequest($matter, $document);

                    $version = $this->documents->appendVersion($document, $versionUuid, $filename, $finalPath, (int) filesize($output), $sha256,
                        $signerType === SignerType::Admin ? DocumentOrigin::Admin : DocumentOrigin::Client,
                        $actor?->id, $signerName.' (signature)', DocumentVersionKind::Signed);

                    $record = new SignatureRecord;
                    $record->forceFill([
                        'signing_request_id' => $request->id,
                        'document_id' => $document->id,
                        'signer_type' => $signerType,
                        'signer_user_id' => $actor?->id,
                        'signer_name' => $request->signer_name,
                        'signer_email' => $request->signer_email,
                        'confirmed_name' => $confirmedName,
                        'signature_method' => $appearance->method,
                        'page_number' => $placement->page,
                        'placement' => $placement->toArray(),
                        'source_document_version_id' => $source->id,
                        'source_sha256' => $source->sha256_checksum,
                        'signed_document_version_id' => $version->id,
                        'signed_sha256' => $sha256,
                        'consent_version' => SigningConsent::VERSION,
                        'consent_text' => SigningConsent::TEXT,
                        'consent_accepted_at' => $signedAt,
                        'signed_at' => $signedAt,
                        'ip_address' => $ip !== null ? substr($ip, 0, 45) : null,
                        'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                    ])->save();

                    $from = $request->status;
                    $request->forceFill([
                        'status' => SigningStatus::Signed,
                        'signed_at' => $signedAt,
                        'signed_document_version_id' => $version->id,
                    ])->save();
                    if ($link !== null) {
                        $consumed = SecureAccessLink::query()->whereKey($link->id)->whereNull('consumed_at')->whereNull('revoked_at')
                            ->update(['successful_uses' => $link->successful_uses + 1, 'consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                        if ($consumed !== 1) {
                            throw new ConflictHttpException('This signing link is no longer valid. Nothing was signed.');
                        }
                    }

                    $metadata = [
                        'signing_request_id' => $request->id,
                        'document_id' => $document->id,
                        'source_version_id' => $source->id,
                        'source_version_number' => $source->version_number,
                        'signed_version_id' => $version->id,
                        'signed_version_number' => $version->version_number,
                        'signed_sha256' => $sha256,
                        'signer_type' => $signerType->value,
                        'signature_method' => $appearance->method->value,
                        'from' => $from->value,
                        'to' => SigningStatus::Signed->value,
                    ];
                    $completed = "Document signed by {$request->signer_name}: {$document->title} ({$source->label()} → {$version->label()}).";
                    $added = "Signed PDF stored as {$version->label()} of {$document->title}.";
                    if ($signerType === SignerType::Client) {
                        $this->activity->recordClient($matter, ActivityEventType::SigningCompleted, $completed, $request->signer_name, $request->id, $request, [...$metadata, 'source' => 'secure_link']);
                        $this->activity->recordClient($matter, ActivityEventType::DocumentSigned, $added, $request->signer_name, $request->id, $version, $metadata);
                        // Phase 6: in the same transaction as the signature it reports.
                        app(AdminNotifier::class)->notify(NotificationKind::DocumentSigned, $matter,
                            "{$request->signer_name} signed \"{$document->title}\". The signed PDF is stored as {$version->label()}.",
                            "/matters/{$matter->id}/signatures/{$request->id}", ['signing_request_id' => $request->id, 'document_id' => $document->id]);
                    } else {
                        $this->activity->record($matter, ActivityEventType::SigningCompleted, $completed, $actor, $request, $metadata);
                        $this->activity->record($matter, ActivityEventType::DocumentSigned, $added, $actor, $version, $metadata);
                    }
                    $this->documents->touchMatter($matter);

                    return $request;
                });
        } finally {
            @unlink($output);
            @unlink($temp);
        }
    }

    private function assertAdminSigner(SigningRequest $request, User $actor): void
    {
        if ($request->signer_type !== SignerType::Admin) {
            throw new ConflictHttpException('This request is for a client signer; the client signs through the secure link.');
        }
        if ($request->signer_user_id !== $actor->id) {
            throw new ConflictHttpException('This signing request is designated to another administrator.');
        }
    }

    /** Case-, space- and accent-insensitive comparison of a confirmed name with the designated signer. */
    public static function sameName(string $a, string $b): bool
    {
        $normalise = function (string $s): string {
            $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

            return is_string($ascii) && $ascii !== '' ? preg_replace('/[^a-z0-9 ]/', '', $ascii) ?? $s : $s;
        };

        return $a !== '' && $normalise($a) === $normalise($b);
    }
}
