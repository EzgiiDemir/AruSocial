<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Support\ContentCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAdminEventRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and title are required.';

    public function rules(): array
    {
        return [
            'id' => ['required'],
            'title' => ['required'],
            'category' => ['nullable', 'string', Rule::in(ContentCategories::ACTIVITY)],
        ];
    }
}
