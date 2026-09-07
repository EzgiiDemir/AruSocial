<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Models\ConsultationApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsultationApplicationRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid consultation application update.';

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(ConsultationApplication::STATUSES)],
            'adminNotes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
