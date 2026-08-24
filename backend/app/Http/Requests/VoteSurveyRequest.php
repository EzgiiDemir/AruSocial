<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class VoteSurveyRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'optionIds is required.';

    public function rules(): array
    {
        return [
            'optionIds' => ['required'],
        ];
    }
}
