<?php

namespace App\Http\Requests\Clients;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and editing clients. Laravel's TrimStrings and
 * ConvertEmptyStringsToNull middleware run first, so "" becomes null for optional fields.
 */
abstract class ClientRequest extends FormRequest
{
    /** Digits, spaces and + ( ) - . / ; at least 5 digits overall (checked in after()). */
    public const PHONE_PATTERN = '/^\+?[0-9 ()\-.\/]{5,50}$/';

    public const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/';

    abstract protected function isUpdate(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        /** @var Client|null $client */
        $client = $this->route('client');

        return [
            'name' => [$required, 'string', 'max:200'],
            'client_type' => [$required, Rule::enum(ClientType::class)],
            'email' => ['nullable', 'string', 'max:255', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:'.self::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:1000'],
            'reference_number' => [
                'nullable', 'string', 'max:50', 'regex:'.self::REFERENCE_PATTERN,
                Rule::unique('clients', 'reference_number')->ignore($client?->getKey()),
            ],
            // Server-managed attributes cannot be supplied.
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $phone = $this->input('phone');
            if (is_string($phone) && preg_match_all('/\d/', $phone) < 5) {
                $validator->errors()->add('phone', 'The phone number must contain at least 5 digits.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Use digits, spaces and + ( ) - . / only.',
            'reference_number.regex' => 'Use letters, numbers and - _ . / only.',
            'reference_number.unique' => 'Another client already uses this reference number.',
        ];
    }
}
