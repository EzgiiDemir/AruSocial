<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertStaffProfileRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'name is required.';

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:200'],
            'faculty' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'isDepartmentHead' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'userId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
