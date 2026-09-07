<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Support\ContentCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTrainerEventRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'title and placeId are required.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'title' => ['required', 'string', 'max:200'],
            'placeId' => ['required', 'string'],
            'eventDate' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', Rule::in(ContentCategories::ACTIVITY)],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
