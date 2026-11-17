<?php

namespace App\Http\Requests\DocumentRequests;

use App\Http\Requests\Documents\PdfUploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of a client upload. Authorisation is the secure link itself (resolved by the controller BEFORE
 * this validation runs, so an unknown token never causes a PDF to be parsed). The same PDF rules as
 * administrator uploads apply (size, extension, byte-level PdfInspector checks).
 */
class PublicSubmissionRequest extends FormRequest
{
    use PdfUploadRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => $this->pdfFileRules(),
            'message' => ['nullable', 'string', 'max:2000'],
            ...$this->prohibitedServerFields(),
            'document_request_id' => ['prohibited'],
            'token' => ['prohibited'],
            'title' => ['prohibited'],
            'category' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->pdfFileMessages(),
            'message.max' => 'Please keep your message under 2,000 characters.',
            '*.prohibited' => 'This field cannot be sent.',
        ];
    }

    /** Plain text only: control characters are removed, empty becomes null. */
    public function clientMessage(): ?string
    {
        $message = preg_replace('/[^\P{C}\n\t]/u', '', (string) $this->validated('message', '')) ?? '';
        $message = trim($message);

        return $message === '' ? null : $message;
    }
}
