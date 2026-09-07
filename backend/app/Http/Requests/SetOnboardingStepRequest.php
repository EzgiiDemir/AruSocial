<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class SetOnboardingStepRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'completed (boolean) is required.';

    public function rules(): array
    {
        return [
            'completed' => ['required', 'boolean'],
        ];
    }
}
