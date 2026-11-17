<?php

namespace App\Services\Requests;

use App\Enums\ActivityEventType;
use App\Enums\DocumentOrigin;
use App\Enums\DocumentRequestStatus;
use App\Enums\NotificationKind;
use App\Enums\SubmissionReviewStatus;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestSubmission;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Services\ActivityLogger;
use App\Services\Communications\AdminNotifier;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A client's PDF upload through a secure link, on top of the Phase 3 pipeline (PdfInspector in the
 * request validation, DocumentService::persistUpload for private staging/verification/cleanup and
 * immutable versions).
 *
 * In ONE transaction, after locking matter → request → link (→ document) and re-checking every link
 * rule (a revocation, approval, cancellation or archive that happened during the upload wins):
 *   first submission     → new document in the request's matter (origin = client), immutable v1
 *   corrected submission → next immutable version of the SAME document (earlier versions untouched)
 *   then: submission row, request → submitted, link consumed, activity event (actor = client).
 * If any step fails nothing is committed and the newly stored file is deleted; the link is consumed
 * only together with a committed version.
 */
class ClientSubmissionService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly PublicUploadLinkResolver $resolver,
        private readonly ActivityLogger $activity,
        private readonly AdminNotifier $notifier,
    ) {}

    public function submit(SecureAccessLink $link, UploadedFile $file, ?string $message): DocumentRequestSubmission
    {
        $request = $link->documentRequest;
        $existingDocument = $request->document_id !== null ? Document::query()->findOrFail($request->document_id) : null;
        $documentUuid = $existingDocument?->uuid ?? Str::uuid()->toString();

        return $this->documents->persistUpload($file, $request->matter_id, $documentUuid,
            function (string $finalPath, string $sha256, string $versionUuid) use ($link, $request, $file, $message, $existingDocument, $documentUuid) {
                $matter = Matter::query()->lockForUpdate()->findOrFail($request->matter_id);
                $request = DocumentRequest::query()->with('client:id,name')->lockForUpdate()->findOrFail($request->id);
                $link = SecureAccessLink::query()->lockForUpdate()->findOrFail($link->id);
                $this->resolver->assertUsable($link, $request, $matter);
                if ($request->document_id !== $existingDocument?->id) {
                    // Cannot happen with one usable link per request; refuse rather than guess.
                    throw new ConflictHttpException('This request changed while the file was uploading. Please try again.');
                }

                $clientName = $request->client->name;
                $filename = $this->documents->safeFilename($file->getClientOriginalName());
                $correction = $request->status === DocumentRequestStatus::CorrectionRequested;

                if ($existingDocument === null) {
                    $document = $this->documents->insertDocument($matter, $documentUuid, $request->title, null,
                        $request->requested_category, DocumentOrigin::Client, null);
                } else {
                    $document = Document::query()->whereBelongsTo($matter)->with('currentVersion')->lockForUpdate()->findOrFail($existingDocument->id);
                    if ($document->isArchived()) {
                        throw PublicLinkException::because('request_unavailable');
                    }
                    $this->documents->rejectSameAsCurrent($document, $sha256,
                        'This file is identical to the one you submitted before. Please upload the corrected PDF.');
                }
                $version = $this->documents->appendVersion($document, $versionUuid, $filename, $finalPath, $file->getSize(),
                    $sha256, DocumentOrigin::Client, null, Str::limit($clientName, 180, '').' (client)');

                $now = Carbon::now();
                $number = $request->submissions_count + 1;
                $submission = new DocumentRequestSubmission;
                $submission->forceFill([
                    'document_request_id' => $request->id,
                    'submission_number' => $number,
                    'document_id' => $document->id,
                    'document_version_id' => $version->id,
                    'secure_access_link_id' => $link->id,
                    'client_message' => $message,
                    'submitted_by_label' => Str::limit($clientName, 200, ''),
                    'submitted_at' => $now,
                    'review_status' => SubmissionReviewStatus::Pending,
                ])->save();

                $from = $request->status;
                $request->forceFill([
                    'status' => DocumentRequestStatus::Submitted,
                    'document_id' => $document->id,
                    'submissions_count' => $number,
                    'last_submitted_at' => $now,
                ])->save();

                // Consume the one-use link — in the same transaction as the version it produced.
                $consumed = SecureAccessLink::query()->whereKey($link->id)->whereNull('consumed_at')->whereNull('revoked_at')
                    ->update(['successful_uses' => $link->successful_uses + 1, 'consumed_at' => $now, 'updated_at' => $now]);
                if ($consumed !== 1) {
                    throw PublicLinkException::because('link_used');
                }

                $this->activity->recordClient($matter,
                    $correction ? ActivityEventType::DocumentResubmitted : ActivityEventType::DocumentSubmitted,
                    ($correction ? 'Corrected document submitted by the client: ' : 'Document submitted by the client: ')."{$request->title} ({$version->label()}).",
                    $clientName, $request->id, $submission, [
                        ...$this->documents->versionMetadata($document, $version),
                        'request_id' => $request->id,
                        'submission_id' => $submission->id,
                        'submission_number' => $number,
                        'from' => $from->value,
                        'to' => DocumentRequestStatus::Submitted->value,
                        'source' => 'secure_link',
                        'link_id' => $link->id,
                        'has_client_message' => $message !== null,
                    ]);
                // Phase 6: tell the administrators — in the same transaction as the submission it reports.
                $this->notifier->notify($correction ? NotificationKind::CorrectionSubmitted : NotificationKind::DocumentSubmitted, $matter,
                    ($correction ? 'A corrected version' : 'A document')." was uploaded for \"{$request->title}\" ({$version->label()}). Review it in the request.",
                    "/matters/{$matter->id}/requests/{$request->id}", ['request_id' => $request->id, 'submission_id' => $submission->id]);
                $this->documents->touchMatter($matter);

                return $submission->setRelation('version', $version);
            });
    }
}
