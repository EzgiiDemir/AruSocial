<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertSportRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and name are required.';

    public function rules(): array
    {
        return [
            'id' => [
                0 => 'required',
            ],
            'name' => [
                0 => 'required',
            ],
        ];
    }
}
