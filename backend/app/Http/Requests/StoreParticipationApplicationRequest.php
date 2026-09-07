<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParticipationApplicationRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'targetType and targetId are required.';

    public function rules(): array
    {
        return [
            'targetType' => ['required', Rule::in(['club', 'sport', 'service', 'career', 'community', 'help', 'event'])],
            'targetId' => ['required', 'string'],
            'responsibleStaffId' => ['nullable', 'string'],
            'formPayload' => ['nullable', 'array'],
        ];
    }
}
