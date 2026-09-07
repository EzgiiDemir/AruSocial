<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveModerationQueueRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'action must be approved or rejected.';

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approved', 'rejected'])],
        ];
    }
}
