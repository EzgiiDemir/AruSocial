<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertAchievementDefinitionRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'title and triggerKind are required.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'subtitle' => ['nullable', 'string'],
            'triggerKind' => ['required', 'string'],
            'threshold' => ['nullable', 'integer', 'min:1'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
