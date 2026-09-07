<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Models\CareerApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCareerApplicationRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid application update.';

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(CareerApplication::STATUSES)],
            'adminNotes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
