<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertSurveyRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'question and at least 2 options are required.';

    public function rules(): array
    {
        return [
            'question' => ['required'],
            'options' => ['required', 'array', 'min:2'],
        ];
    }
}
