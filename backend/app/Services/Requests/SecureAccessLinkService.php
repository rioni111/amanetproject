<?php

namespace App\Services\Requests;

use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Secure access links ("bearer links") for external clients.
 *
 * Token:   32 bytes from the operating system CSPRNG (random_bytes) = 256 bits of entropy, encoded as
 *          43 characters of base64url. Nothing about it is derived from ids, times or other data.
 * Storage: only SHA-256(token) is stored (unique index). A database leak therefore exposes no usable
 *          link; with 256-bit tokens a plain (unsalted) hash cannot be brute-forced.
 * Lookup:  by hash (an index lookup reveals nothing about the token), then hash_equals.
 * Scope:   every link has one purpose; each purpose has its own endpoints, which only accept their own.
 * Use:     one successful submission per upload link (max_uses = 1). Viewing never consumes it.
 * Rotation/revocation take effect immediately: they are row updates checked on every request.
 *
 * Callers run these methods inside their own transaction, after locking the request row.
 */
class SecureAccessLinkService
{
    public const TOKEN_BYTES = 32;

    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** Default and maximum validity (days) for upload links; see config/lexora.php. */
    public function expiryDays(?int $requested): int
    {
        $max = max(1, (int) config('lexora.secure_links.max_expiry_days', 30));

        return min($max, max(1, $requested ?? (int) config('lexora.secure_links.upload_expiry_days', 7)));
    }

    /**
     * Issues a new upload link for a (locked) request. Any previous link that could still be used —
     * including an expired one — is revoked first, so exactly one link can work at any time (also
     * enforced by a partial unique index).
     */
    public function issueUploadLink(DocumentRequest $request, User $actor, ?int $expiresInDays = null): IssuedLink
    {
        $replaced = $this->revokeUsableForRequest($request, LinkRevocationReason::Replaced, $actor);

        // A collision of two 256-bit random values is not a realistic event; the unique index guards it anyway.
        $token = self::generateToken();
        $now = Carbon::now();
        $link = new SecureAccessLink;
        $link->forceFill([
            'uuid' => Str::uuid()->toString(),
            'purpose' => SecureLinkPurpose::DocumentUpload,
            'matter_id' => $request->matter_id,
            'document_request_id' => $request->id,
            'token_hash' => self::hashToken($token),
            'expires_at' => $now->copy()->addDays($this->expiryDays($expiresInDays)),
            'max_uses' => 1,
            'successful_uses' => 0,
            'created_by' => $actor->id,
        ])->save();

        return new IssuedLink($link, $this->uploadUrl($token), $replaced);
    }

    public function revoke(SecureAccessLink $link, LinkRevocationReason $reason, ?User $actor): bool
    {
        return SecureAccessLink::query()
            ->whereKey($link->getKey())
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revocation_reason' => $reason->value,
                'revoked_by' => $actor?->id,
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    /** @return int number of links revoked */
    public function revokeUsableForRequest(DocumentRequest $request, LinkRevocationReason $reason, ?User $actor): int
    {
        return SecureAccessLink::query()
            ->where('document_request_id', $request->id)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revocation_reason' => $reason->value,
                'revoked_by' => $actor?->id,
                'updated_at' => Carbon::now(),
            ]);
    }

    /** Archiving a matter immediately retires every link that could still be used for it. */
    public function revokeUsableForMatter(Matter $matter, LinkRevocationReason $reason, ?User $actor): int
    {
        return SecureAccessLink::query()
            ->where('matter_id', $matter->id)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revocation_reason' => $reason->value,
                'revoked_by' => $actor?->id,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * The client-facing URL. The token travels in the URL FRAGMENT (#…): browsers never send a
     * fragment to any server, so it cannot appear in web-server or Next.js request logs, proxy logs,
     * Referer headers or server-side caches. The page reads it in the browser and sends it to the API
     * in a request header (not the URL), which keeps it out of the API's access log as well.
     */
    public function uploadUrl(string $token): string
    {
        return config('lexora.public_app_url').'/client-upload#'.$token;
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost');
    }
}
