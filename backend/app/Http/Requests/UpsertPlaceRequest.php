<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Support\ContentCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPlaceRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, name, category, lat and lng are required.';

    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', Rule::in(ContentCategories::CAMPUS_FUNCTION)],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string'],
            'distance' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:200'],
            'tourUrl' => ['nullable', 'url:http,https', 'max:1000'],
            'tourTarget' => ['nullable', 'string', 'max:500'],
            'accessible' => ['sometimes', 'boolean'],
        ];
    }
}
