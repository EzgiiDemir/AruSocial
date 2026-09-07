<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class BookAppointmentRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'staffProfileId, date, startTime, endTime and subject are required.';

    public function rules(): array
    {
        return [
            'staffProfileId' => ['required', 'string'],
            'date' => ['required', 'date'],
            'startTime' => ['required', 'string', 'max:8'],
            'endTime' => ['required', 'string', 'max:8'],
            'subject' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'applicationId' => ['nullable', 'string'],
        ];
    }
}
