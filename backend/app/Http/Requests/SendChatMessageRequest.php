<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'text is required.';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'text' => trim((string) $this->input('text', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'text' => [
                0 => 'required',
            ],
        ];
    }
}
