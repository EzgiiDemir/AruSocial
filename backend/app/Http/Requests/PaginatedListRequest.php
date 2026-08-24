<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class PaginatedListRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'page must be an integer >= 1 and perPage must be an integer between 1 and 50.';

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
