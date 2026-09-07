<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Support\ContentCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertClubRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and name are required.';

    public function rules(): array
    {
        return [
            'id' => ['required'],
            'name' => ['required'],
            'category' => ['nullable', 'string', Rule::in(ContentCategories::ACTIVITY)],
        ];
    }
}
