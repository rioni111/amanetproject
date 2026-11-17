<?php

namespace App\Services\Signing;

use App\Enums\ActivityEventType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentRequestStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\LinkRevocationReason;
use App\Enums\SignerType;
use App\Enums\SigningCancellationReason;
use App\Enums\SigningStatus;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Documents\DocumentStorage;
use App\Services\Requests\IssuedLink;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Administrator management of signing requests: eligibility, creation (one signer, one pinned source
 * version), signing links, "invitation shared" confirmation and cancellation. Every method is one
 * transaction with its activity events; lock order matter → document → signing request → link.
 */
class SigningRequestService
{
    public const STALE_MESSAGE = 'This signing request changed after you opened it (for example, the signer opened or signed it). Reload to see the latest state.';

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SecureAccessLinkService $links,
        private readonly SigningLifecycle $lifecycle,
        private readonly PdfSigningService $engine,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * Server-side eligibility of a source version for a NEW signing request. Returns the displayed page sizes.
     *
     * @return list<array{width: float, height: float}>
     */
    public function eligibility(Matter $matter, Document $document, DocumentVersion $version, bool $checkActive = true): array
    {
        if ($matter->isArchived()) {
            throw new ConflictHttpException('This matter is archived and read-only. Restore it to sign documents.');
        }
        if ($document->matter_id !== $matter->id || $version->document_id !== $document->id) {
            throw ValidationException::withMessages(['document_version_id' => ['This version does not belong to the document.']]);
        }
        if ($document->isArchived()) {
            throw new ConflictHttpException('This document is archived. Restore it before signing.');
        }
        if ($version->kind !== DocumentVersionKind::Upload) {
            throw ValidationException::withMessages(['document_version_id' => ['This version is already a signed PDF. Signed documents cannot be signed again (one signer per PDF).']]);
        }
        if ($document->current_version_id !== $version->id) {
            throw ValidationException::withMessages(['document_version_id' => ['Only the current version of a document can be signed. Open the latest version.']]);
        }
        if ($checkActive && SigningRequest::query()->where('document_id', $document->id)->whereIn('status', SigningStatus::activeValues())->exists()) {
            throw new ConflictHttpException('This document already has an open signing request. Complete or cancel it first.');
        }
        // Client submissions must be reviewed first: signing never approves a submission implicitly.
        $openReview = DocumentRequest::query()->where('document_id', $document->id)
            ->whereIn('status', DocumentRequestStatus::openValues())->exists();
        if ($openReview) {
            throw new ConflictHttpException('This document belongs to a client request that is still being reviewed. Approve the submission before signing it.');
        }

        $path = $this->storage->absolutePath($version->storage_path);
        if (! is_file($path) || ! hash_equals($version->sha256_checksum, (string) hash_file('sha256', $path))) {
            throw new ConflictHttpException('The stored file of this version is missing or failed its integrity check, so it cannot be signed.');
        }
        try {
            return $this->engine->inspect($path);
        } catch (UnsupportedPdfException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  validated: document_id, document_version_id, signer_type, signer_name?, signer_email?, message?
     * @return array{0: SigningRequest, 1: IssuedLink|null}
     */
    public function create(Matter $matter, array $data, User $actor, bool $issueLink = false, ?int $expiresInDays = null): array
    {
        return DB::transaction(function () use ($matter, $data, $actor, $issueLink, $expiresInDays) {
            $matter = Matter::query()->with('client:id,name,email')->lockForUpdate()->findOrFail($matter->getKey());
            if ($matter->isArchived()) {
                throw new ConflictHttpException('This matter is archived and read-only. Restore it to sign documents.');
            }
            $document = Document::query()->whereBelongsTo($matter)->lockForUpdate()->find($data['document_id'])
                ?? throw ValidationException::withMessages(['document_id' => ['Choose a document of this matter.']]);
            $version = DocumentVersion::query()->where('document_id', $document->id)->find($data['document_version_id'])
                ?? throw ValidationException::withMessages(['document_version_id' => ['Choose a version of this document.']]);
            $this->eligibility($matter, $document, $version);

            $request = $this->newRequest($matter, $document, $version, SignerType::from($data['signer_type']), $data, $actor);
            $request->save();
            $this->recordRequested($matter, $request, $document, $version, $actor);

            $issued = $issueLink && $request->isClientSigner() ? $this->issue($matter, $request, $actor, $expiresInDays, false) : null;

            return [$request, $issued];
        });
    }

    /** Builds (unsaved) a request. Admin signers are always the acting administrator; client signers the matter's client. */
    public function newRequest(Matter $matter, Document $document, DocumentVersion $version, SignerType $type, array $data, User $actor): SigningRequest
    {
        $request = new SigningRequest;
        $request->forceFill([
            'uuid' => Str::uuid()->toString(),
            'matter_id' => $matter->id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'signer_type' => $type,
            'signer_user_id' => $type === SignerType::Admin ? $actor->id : null,
            'client_id' => $type === SignerType::Client ? $matter->client_id : null,
            'signer_name' => $type === SignerType::Admin ? $actor->name : trim((string) $data['signer_name']),
            'signer_email' => $type === SignerType::Admin ? $actor->email : ($data['signer_email'] ?? null),
            'message' => $type === SignerType::Client ? ($data['message'] ?? null) : null,
            'status' => SigningStatus::Ready,
            'delivery_status' => DeliveryStatus::NotShared,
            'created_by' => $actor->id,
        ]);

        return $request;
    }

    public function recordRequested(Matter $matter, SigningRequest $request, Document $document, DocumentVersion $version, User $actor): void
    {
        $this->activity->record($matter, ActivityEventType::SigningRequested,
            "Signature requested from {$request->signer_name} ({$request->signer_type->label()}): {$document->title} ({$version->label()}).",
            $actor, $request, [
                'signing_request_id' => $request->id,
                'document_id' => $document->id,
                'source_version_id' => $version->id,
                'source_version_number' => $version->version_number,
                'signer_type' => $request->signer_type->value,
            ]);
    }

    public function issueLink(Matter $matter, SigningRequest $request, User $actor, ?int $expiresInDays, ?int $lockVersion): IssuedLink
    {
        return DB::transaction(function () use ($matter, $request, $actor, $expiresInDays, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);
            $usable = SecureAccessLink::query()->where('signing_request_id', $request->id)->whereNull('revoked_at')->whereNull('consumed_at')->first();
            if ($usable !== null && $usable->isUsable()) {
                throw new ConflictHttpException('This request already has an active signing link. Generate a replacement link to get a new one.');
            }

            return $this->issue($matter, $request, $actor, $expiresInDays, false);
        });
    }

    public function rotateLink(Matter $matter, SigningRequest $request, User $actor, ?int $expiresInDays, ?int $lockVersion): IssuedLink
    {
        return DB::transaction(function () use ($matter, $request, $actor, $expiresInDays, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);

            return $this->issue($matter, $request, $actor, $expiresInDays, true);
        });
    }

    public function revokeLink(Matter $matter, SigningRequest $request, SecureAccessLink $link, User $actor): SecureAccessLink
    {
        return DB::transaction(function () use ($matter, $request, $link, $actor) {
            [$matter, $request] = $this->lock($matter, $request, null, writable: false);
            $link = SecureAccessLink::query()->where('signing_request_id', $request->id)->lockForUpdate()->findOrFail($link->getKey());
            if ($link->revoked_at !== null || $link->consumed_at !== null) {
                throw new ConflictHttpException($link->consumed_at !== null ? 'This link was used to sign; it no longer grants access.' : 'This link has already been revoked.');
            }
            $this->links->revoke($link, LinkRevocationReason::Revoked, $actor);
            $this->touch($request);
            $this->activity->record($matter, ActivityEventType::SigningLinkRevoked,
                "Signing link revoked for {$request->signer_name}.", $actor, $request, [
                    'signing_request_id' => $request->id, 'link_id' => $link->id, 'was_expired' => $link->expires_at->isPast(),
                ]);

            return $link->refresh();
        });
    }

    /**
     * Records that the ADMINISTRATOR shared the invitation outside the app (Phase 5 manual channel). This is
     * the only way to reach "sent" in Phase 5 — generating or copying a link never does. No email is claimed.
     */
    public function markShared(Matter $matter, SigningRequest $request, User $actor, ?int $lockVersion): SigningRequest
    {
        return DB::transaction(function () use ($matter, $request, $actor, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion);
            if (! $request->isClientSigner()) {
                throw new ConflictHttpException('Administrator signing requests are not shared with anyone.');
            }
            if ($request->status !== SigningStatus::Ready) {
                throw new ConflictHttpException('The invitation can only be marked as shared before the signer has opened it.');
            }
            $usable = SecureAccessLink::query()->where('signing_request_id', $request->id)->whereNull('revoked_at')->whereNull('consumed_at')->first();
            if ($usable === null || ! $usable->isUsable()) {
                throw new ConflictHttpException('Generate an active signing link before marking the invitation as shared.');
            }
            $request->forceFill([
                'status' => SigningStatus::Sent,
                'sent_at' => Carbon::now(),
                'delivery_channel' => DeliveryChannel::Manual,
                'delivery_status' => DeliveryStatus::SharedManually,
            ])->save();
            $this->activity->record($matter, ActivityEventType::SigningInvitationShared,
                "Signing invitation marked as shared by the administrator (outside the app): {$request->signer_name}.", $actor, $request, [
                    'signing_request_id' => $request->id, 'from' => SigningStatus::Ready->value, 'to' => SigningStatus::Sent->value,
                    'delivery_channel' => DeliveryChannel::Manual->value, 'link_id' => $usable->id,
                ]);

            return $request;
        });
    }

    public function cancel(Matter $matter, SigningRequest $request, User $actor, ?int $lockVersion): SigningRequest
    {
        return DB::transaction(function () use ($matter, $request, $actor, $lockVersion) {
            [$matter, $request] = $this->lock($matter, $request, $lockVersion, writable: false);
            if (! $request->status->isActive()) {
                throw new ConflictHttpException("A {$request->status->label()} signing request cannot be cancelled.");
            }
            $this->lifecycle->cancel($matter, $request, SigningCancellationReason::Manual, $actor);

            return $request;
        });
    }

    /** Issues a signing link inside the caller's transaction (request locked). */
    private function issue(Matter $matter, SigningRequest $request, User $actor, ?int $expiresInDays, bool $rotation): IssuedLink
    {
        $matter->assertNotArchived();
        if (! $request->isClientSigner()) {
            throw new ConflictHttpException('Administrator signing requests are signed inside the workspace and have no link.');
        }
        if (! $request->status->isActive()) {
            throw new ConflictHttpException('This signing request is closed; no link can be issued.');
        }
        $issued = $this->links->issueSigningLink($request, $actor, $expiresInDays);
        $this->touch($request);
        $type = $rotation && $issued->replacedLinks > 0 ? ActivityEventType::SigningLinkRotated : ActivityEventType::SigningLinkCreated;
        $this->activity->record($matter, $type,
            ($type === ActivityEventType::SigningLinkRotated ? 'Replacement signing link generated for ' : 'Signing link generated for ')."{$request->signer_name}.",
            $actor, $request, [
                'signing_request_id' => $request->id, 'link_id' => $issued->link->id,
                'expires_at' => $issued->link->expires_at->toIso8601String(), 'links_revoked' => $issued->replacedLinks,
            ]);

        return $issued;
    }

    /** @return array{0: Matter, 1: SigningRequest} */
    public function lock(Matter $matter, SigningRequest $request, ?int $lockVersion, bool $writable = true): array
    {
        $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        if ($writable) {
            $matter->assertNotArchived();
        }
        $request = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($request->getKey());
        $request->assertLockVersion($lockVersion, self::STALE_MESSAGE);

        return [$matter, $request];
    }

    /** Link changes are request changes: always bump lock_version (even when the clock has not moved). */
    private function touch(SigningRequest $request): void
    {
        SigningRequest::query()->whereKey($request->id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => Carbon::now()]);
        $request->refresh();
    }
}
