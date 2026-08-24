<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UnregisterPushTokenRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'token is required.';

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }
}
