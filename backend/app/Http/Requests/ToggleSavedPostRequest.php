<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class ToggleSavedPostRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'postId is required.';

    public function rules(): array
    {
        return [
            'postId' => [
                0 => 'required',
            ],
        ];
    }
}
