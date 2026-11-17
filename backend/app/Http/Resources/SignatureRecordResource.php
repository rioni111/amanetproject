<?php

namespace App\Http\Resources;

use App\Models\SignatureRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Signature evidence for administrators: who (designated + confirmed name), how, where, which exact source
 * and signed versions (with SHA-256), consent and server timestamps. No signature strokes, tokens or paths.
 *
 * @mixin SignatureRecord
 */
class SignatureRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signing_request_id' => $this->signing_request_id,
            'signer_type' => $this->signer_type->value,
            'signer_name' => $this->signer_name,
            'signer_email' => $this->signer_email,
            'confirmed_name' => $this->confirmed_name,
            'signer_user_id' => $this->signer_user_id,
            'identity_basis' => $this->signer_type->value === 'admin'
                ? 'Authenticated administrator account (Lexora session).'
                : 'Possession of the secure signing link and the name the signer confirmed. No independent identity verification.',
            'signature_method' => $this->signature_method->value,
            'signature_method_label' => $this->signature_method->label(),
            'page_number' => $this->page_number,
            'placement' => $this->placement,
            'source_version_id' => $this->source_document_version_id,
            'source_sha256' => $this->source_sha256,
            'signed_version_id' => $this->signed_document_version_id,
            'signed_sha256' => $this->signed_sha256,
            'consent_version' => $this->consent_version,
            'consent_text' => $this->consent_text,
            'consent_accepted_at' => $this->consent_accepted_at->toIso8601String(),
            'signed_at' => $this->signed_at->toIso8601String(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'source_version' => new DocumentVersionResource($this->whenLoaded('sourceVersion')),
            'signed_version' => new DocumentVersionResource($this->whenLoaded('signedVersion')),
        ];
    }
}
