<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class DraftEventFromPosterRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'A poster image file is required.';

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }
}
