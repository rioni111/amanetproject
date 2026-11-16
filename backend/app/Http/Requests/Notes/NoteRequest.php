<?php

namespace App\Http\Requests\Notes;

use Illuminate\Foundation\Http\FormRequest;

/** Note body is plain text (rendered as text, never as HTML). Author and matter come from the server. */
class NoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'lock_version' => $this->isMethod('PATCH') ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'author_id' => ['prohibited'],
            'matter_id' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Write the note before saving.'];
    }
}
