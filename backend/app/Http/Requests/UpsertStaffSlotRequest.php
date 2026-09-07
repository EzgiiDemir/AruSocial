<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertStaffSlotRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'date, startTime and endTime are required.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'date' => ['required', 'date'],
            'startTime' => ['required', 'string'],
            'endTime' => ['required', 'string'],
            'isBlocked' => ['sometimes', 'boolean'],
        ];
    }
}
