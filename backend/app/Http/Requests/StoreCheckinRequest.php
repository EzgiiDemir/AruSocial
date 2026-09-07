<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class StoreCheckinRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'placeId, latitude and longitude are required.';

    public function rules(): array
    {
        return [
            'placeId' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'visibleToOthers' => ['sometimes', 'boolean'],
        ];
    }
}
