<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCareerProfileRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid career profile fields.';

    public function rules(): array
    {
        return [
            'occupation' => ['nullable', 'string', 'max:200'],
            'headline' => ['nullable', 'string', 'max:200'],
            'expertise' => ['nullable', 'string', 'max:200'],
            'lookingForInternships' => ['sometimes', 'boolean'],
            'lookingForJobs' => ['sometimes', 'boolean'],
        ];
    }
}
