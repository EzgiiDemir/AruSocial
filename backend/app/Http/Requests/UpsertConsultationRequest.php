<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertConsultationRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid consultation fields.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:200'],
            'purpose' => ['nullable', 'string', 'max:4000'],
            'audience' => ['nullable', 'string', 'max:4000'],
            'content' => ['nullable', 'string', 'max:8000'],
            'outcomes' => ['nullable', 'string', 'max:4000'],
            'duration' => ['nullable', 'string', 'max:120'],
            'format' => ['nullable', 'string', 'max:120'],
            'requirements' => ['nullable', 'string', 'max:4000'],
            'counselorName' => ['nullable', 'string', 'max:200'],
            'counselorStaffId' => ['nullable', 'string', 'max:80'],
            'published' => ['sometimes', 'boolean'],
        ];
    }
}
