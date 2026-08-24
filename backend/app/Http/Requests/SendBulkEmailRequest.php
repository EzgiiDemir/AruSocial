<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class SendBulkEmailRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'recipients, subject and body are required.';

    public function rules(): array
    {
        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $recipients = (array) $this->input('recipients', []);
            if (empty($recipients) || ! $this->input('subject') || ! $this->input('body')) {
                $validator->errors()->add('recipients', $this->validationMessage);
            }
        });
    }
}
