<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class CreateOwnActivityRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'title, placeId and responsibleStaffId are required.';

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'placeId' => ['required', 'string'],
            'responsibleStaffId' => ['required', 'string', 'exists:staff_profiles,id'],
            'time' => ['nullable', 'string', 'max:32'],
            'eventDate' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
