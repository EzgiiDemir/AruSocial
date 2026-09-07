<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid appointment update.';

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(Appointment::STATUSES)],
            'adminNotes' => ['nullable', 'string', 'max:2000'],
            'staffProfileId' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
