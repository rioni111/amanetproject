<?php

namespace App\Services\Requests;

use App\Enums\ActivityEventType;
use App\Enums\DocumentCategory;
use App\Enums\DocumentRequestStatus;
use App\Enums\LinkRevocationReason;
use App\Enums\SubmissionReviewStatus;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\LegalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Administrator side of client document requests: create, edit, issue / rotate / revoke the upload
 * link, cancel. Every method is ONE transaction containing the change and its activity event, and
 * locks rows in the same order everywhere: matter → request → links (→ document).
 */
class DocumentRequestService
{
    public const STALE_MESSAGE = 'This request was changed after you opened it (for example, the client submitted a file). Reload to see the latest state.';

    public function __construct(
        private readonly SecureAccessLinkService $links,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated: title, instructions, requested_category, due_date?
     * @return array{0: DocumentRequest, 1: IssuedLink|null}
     */
    public function create(Matter $matter, array $data, User $actor, bool $issueLink = false, ?int $expiresInDays = null): array
    {
        return DB::transaction(function () use ($matter, $data, $actor, $issueLink, $expiresInDays) {
            $matter = $this->lockWritableMatter($matter);

            $request = new DocumentRequest;
            $request->forceFill([
                'uuid' => Str::uuid()->toString(),
                'matter_id' => $matter->id,
                'client_id' => $matter->client_id, // always the matter's own client — never client-supplied
                'title' => $data['title'],
                'instructions' => $data['instructions'],
                'requested_category' => DocumentCategory::from($data['requested_category']),
                'status' => DocumentRequestStatus::Pending,
                'due_at' => LegalDate::endOfDay($data['due_date'] ?? null),
                'created_by' => $actor->id,
            ])->save();

            $this->activity->record($matter, ActivityEventType::DocumentRequested,
                "Document requested from the client: {$request->title}.", $actor, $request, [
                    'request_id' => $request->id,
                    'category' => $request->requested_category->value,
                    'due_date' => LegalDate::toDate($request->due_at),
                ]);

            $issued = $issueLink ? $this->issue($matter, $request, $actor, $expiresInDays, false) : null;

            return [$request, $issued];
        });
    }

    /** @param array<string, mixed> $data validated: title?, instructions?, requested_category?, due_date?, lock_version */
    public function update(Matter $matter, DocumentRequest $request, array $data, User $actor): DocumentRequest
    {
        return DB::transaction(function () use ($matter, $request, $data, $actor) {
            [$matter, $request] = $this->lock($matter, $request, $data['lock_version'] ?? null);
            $this->assertOpen($request, 'A closed request cannot be edited.');

            $before = ['title' => $request->title, 'requested_category' => $request->requested_category->value, 'due_date' => LegalDate::toDate($request->due_at)];
            foreach (['title', 'instructions'] as $field) {
                if (array_key_exists($field, $data)) {
                    $request->{$field} = $data[$field];
                }
            }
            if (array_key_exists('requested_category', $data)) {
                $request->requested_category = DocumentCategory::from($data['requested_category']);
            }
            if (array_key_exists('due_date', $data) && $data['due_date'] !== $before['due_date']) {
                $request->due_at = LegalDate::endOfDay($data['due_date']);
            }
            if (! $request->isDirty()) {
                return $request;
            }
            $instructionsChanged = $request->isDirty('instructions');
            $request->save();

            $after = ['title' => $request->title, 'requested_category' => $request->requested_category->value, 'due_date' => LegalDate::toDate($request->due_at)];
            $changes = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $value];
                }
            }
            if ($instructionsChanged) {
                $changes['instructions'] = ['changed' => true]; // content may be confidential
            }
            $this->activity->record($matter, ActivityEventType::DocumentRequestUpdated,
                "Document request updated: {$request->title}.", $actor, $request, ['request_id' => $request->id, 'changes' => $changes]);

            return $request;
        });
    }

    /** First link for a request (409 if a usable one exists — use rotate to replace it). */
    public function issueLink(Matter $matter, DocumentRequest $request, User $actor, ?int $expiresInDays, ?int $lockVersion): IssuedLink
    {
        return DB::transaction(function () use ($matter, $request, $actor, $expiresInDays, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);
            $existing = SecureAccessLink::query()->where('document_request_id', $request->id)
                ->whereNull('revoked_at')->whereNull('consumed_at')->first();
            if ($existing !== null && $existing->isUsable()) {
                throw new ConflictHttpException('This request already has an active upload link. Generate a replacement link to get a new one.');
            }

            return $this->issue($matter, $request, $actor, $expiresInDays, false);
        });
    }

    /** Revokes the current link (if any) and issues a replacement. The old link stops working at once. */
    public function rotateLink(Matter $matter, DocumentRequest $request, User $actor, ?int $expiresInDays, ?int $lockVersion): IssuedLink
    {
        return DB::transaction(function () use ($matter, $request, $actor, $expiresInDays, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);

            return $this->issue($matter, $request, $actor, $expiresInDays, true);
        });
    }

    public function revokeLink(Matter $matter, DocumentRequest $request, SecureAccessLink $link, User $actor): SecureAccessLink
    {
        return DB::transaction(function () use ($matter, $request, $link, $actor) {
            [$matter, $request] = $this->lock($matter, $request, null, writable: false);
            $link = SecureAccessLink::query()->where('document_request_id', $request->id)->lockForUpdate()->findOrFail($link->getKey());
            if ($link->revoked_at !== null || $link->consumed_at !== null) {
                throw new ConflictHttpException($link->consumed_at !== null
                    ? 'This link has already been used; it no longer grants access.'
                    : 'This link has already been revoked.');
            }
            $this->links->revoke($link, LinkRevocationReason::Revoked, $actor);
            $this->touch($request);

            $this->activity->record($matter, ActivityEventType::LinkRevoked,
                "Upload link revoked for: {$request->title}.", $actor, $request, [
                    'request_id' => $request->id,
                    'link_id' => $link->id,
                    'was_expired' => $link->expires_at->isPast(),
                ]);

            return $link->refresh();
        });
    }

    public function cancel(Matter $matter, DocumentRequest $request, User $actor, ?int $lockVersion): DocumentRequest
    {
        return DB::transaction(function () use ($matter, $request, $actor, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);
            $from = $request->status;
            if (! $from->canTransitionTo(DocumentRequestStatus::Cancelled)) {
                throw ValidationException::withMessages(['status' => ["A request that is {$from->label()} cannot be cancelled."]]);
            }

            $revoked = $this->links->revokeUsableForRequest($request, LinkRevocationReason::RequestCancelled, $actor);
            $request->forceFill([
                'status' => DocumentRequestStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $actor->id,
            ])->save();

            // A submission still waiting for a decision is closed without one (not altered afterwards).
            $request->submissions()
                ->whereIn('review_status', [SubmissionReviewStatus::Pending->value, SubmissionReviewStatus::InReview->value])
                ->update(['review_status' => SubmissionReviewStatus::Cancelled->value]);

            $this->activity->record($matter, ActivityEventType::DocumentRequestCancelled,
                "Document request cancelled: {$request->title}.", $actor, $request, [
                    'request_id' => $request->id,
                    'from' => $from->value,
                    'to' => DocumentRequestStatus::Cancelled->value,
                    'links_revoked' => $revoked,
                ]);

            return $request;
        });
    }

    // ------------------------------------------------------------------ shared with DocumentReviewService

    /** Issues a link inside the caller's transaction (request already locked). */
    public function issue(Matter $matter, DocumentRequest $request, User $actor, ?int $expiresInDays, bool $rotation): IssuedLink
    {
        $matter->assertNotArchived();
        if (! $request->status->acceptsUploads()) {
            throw new ConflictHttpException(match ($request->status) {
                DocumentRequestStatus::Submitted, DocumentRequestStatus::UnderReview => 'The client has already submitted a file. Review it, or request a correction to send a new link.',
                default => 'This request is closed; no upload link can be issued.',
            });
        }
        $issued = $this->links->issueUploadLink($request, $actor, $expiresInDays);
        $this->touch($request);

        $type = $rotation && $issued->replacedLinks > 0 ? ActivityEventType::LinkRotated : ActivityEventType::LinkCreated;
        $this->activity->record($matter, $type,
            ($type === ActivityEventType::LinkRotated ? 'Replacement upload link generated for: ' : 'Upload link generated for: ')."{$request->title}.",
            $actor, $request, [
                'request_id' => $request->id,
                'link_id' => $issued->link->id,
                'expires_at' => $issued->link->expires_at->toIso8601String(),
                'links_revoked' => $issued->replacedLinks,
            ]);

        return $issued;
    }

    /**
     * Locks matter then request (the order used by every request operation and by client uploads).
     *
     * @return array{0: Matter, 1: DocumentRequest}
     */
    public function lock(Matter $matter, DocumentRequest $request, ?int $lockVersion, bool $writable = true): array
    {
        $matter = $writable ? $this->lockWritableMatter($matter) : Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        $request = DocumentRequest::query()->whereBelongsTo($matter)->with('client:id,name')->lockForUpdate()->findOrFail($request->getKey());
        $request->assertLockVersion($lockVersion, self::STALE_MESSAGE);

        return [$matter, $request];
    }

    public function assertOpen(DocumentRequest $request, string $message): void
    {
        if (! $request->status->isOpen()) {
            throw new ConflictHttpException($message);
        }
    }

    /** Bumps lock_version + updated_at: link changes are request changes for concurrent editors. */
    public function touch(DocumentRequest $request): void
    {
        $request->forceFill(['updated_at' => Carbon::now()])->save();
    }

    private function lockWritableMatter(Matter $matter): Matter
    {
        $locked = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        $locked->assertNotArchived();

        return $locked;
    }
}
