<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPushTokenRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'token and a valid platform are required.';

    public function rules(): array
    {
        return [
            'token' => ['required'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
        ];
    }
}
