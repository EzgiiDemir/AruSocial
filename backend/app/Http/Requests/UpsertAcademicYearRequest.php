<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertAcademicYearRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, label, startsOn and endsOn are required.';

    public function rules(): array
    {
        return [
            'id' => [
                0 => 'required',
            ],
            'label' => [
                0 => 'required',
            ],
            'startsOn' => [
                0 => 'required',
            ],
            'endsOn' => [
                0 => 'required',
            ],
        ];
    }
}
