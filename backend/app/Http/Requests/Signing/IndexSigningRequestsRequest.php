<?php

namespace App\Http\Requests\Signing;

use App\Enums\SignerType;
use App\Enums\SigningStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSigningRequestsRequest extends FormRequest
{
    public const SORTABLE = ['updated_at', 'created_at', 'signed_at', 'matter_number'];

    public function authorize(): bool
    {
        $matter = $this->route('matter');

        return $matter ? ($this->user()?->can('view', $matter) ?? false) : ($this->user()?->isAdmin() ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...SigningStatus::values(), 'active'])],
            'signer_type' => ['nullable', Rule::enum(SignerType::class)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('lexora.pagination.max_per_page')],
        ];
    }
}
