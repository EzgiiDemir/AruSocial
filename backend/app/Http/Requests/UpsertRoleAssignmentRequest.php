<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertRoleAssignmentRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'email is required and role must be a valid UserRole.';

    public function rules(): array
    {
        return [
            'email' => ['required'],
            'role' => ['required', Rule::in([
                'student', 'clubManager', 'contentEditor', 'moderator',
                'careerStaff', 'studentAffairs', 'superAdmin', 'trainer',
            ])],
        ];
    }
}
