<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class ApplyConsultationRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid consultation application.';

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
