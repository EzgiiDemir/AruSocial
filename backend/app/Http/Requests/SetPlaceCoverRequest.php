<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class SetPlaceCoverRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'url is required.';

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
