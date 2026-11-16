<?php

namespace App\Http\Requests\Clients;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexClientsRequest extends FormRequest
{
    public const SORTABLE = ['name', 'created_at', 'matters_count'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Client::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ClientType::class)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('lexora.pagination.max_per_page')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
