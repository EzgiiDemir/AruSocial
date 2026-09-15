<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertPageRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, title and slug are required.';

    public function rules(): array
    {
        return [
            'id' => [
                0 => 'required',
            ],
            'title' => [
                0 => 'required',
            ],
            'slug' => [
                0 => 'required',
            ],
            'translations' => ['sometimes', 'array'],
            'translations.tr' => ['sometimes', 'array'],
            'translations.en' => ['sometimes', 'array'],
            'translations.ru' => ['sometimes', 'array'],
            'blocks' => ['sometimes', 'array'],
            'audiences' => ['sometimes', 'array'],
            'publishAt' => ['nullable', 'date'],
            'expiresAt' => ['nullable', 'date', 'after:publishAt'],
        ];
    }
}
