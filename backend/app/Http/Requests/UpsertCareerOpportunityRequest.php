<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCareerOpportunityRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid career opportunity fields.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['internship', 'job', 'event', 'resource'])],
            'organization' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'url' => ['nullable', 'string', 'max:500'],
            'deadline' => ['nullable', 'date'],
            'postedAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:8000'],
            'purpose' => ['nullable', 'string', 'max:4000'],
            'skills' => ['nullable', 'string', 'max:4000'],
            'experience' => ['nullable', 'string', 'max:4000'],
            'education' => ['nullable', 'string', 'max:4000'],
            'workType' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:200'],
            'extraInfo' => ['nullable', 'string', 'max:4000'],
            'published' => ['sometimes', 'boolean'],
        ];
    }
}
