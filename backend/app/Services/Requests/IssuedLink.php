<?php

namespace App\Services\Requests;

use App\Models\SecureAccessLink;

/**
 * A freshly issued link together with its one-time URL. The URL (which contains the token) exists
 * only in memory and in the HTTP response to the administrator who created it — it is never stored,
 * logged or put into activity metadata, and it cannot be produced again later.
 */
final readonly class IssuedLink
{
    public function __construct(
        public SecureAccessLink $link,
        #[\SensitiveParameter] public string $url,
        public int $replacedLinks = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'id' => $this->link->id,
            'url' => $this->url,
            'expires_at' => $this->link->expires_at->toIso8601String(),
            'is_local_url' => SecureAccessLinkService::isLocalUrl($this->url),
            'shown_once' => true,
        ];
    }

    /** Never print the URL in dumps or logs. */
    public function __debugInfo(): array
    {
        return ['link_id' => $this->link->id, 'url' => '[redacted]'];
    }
}
