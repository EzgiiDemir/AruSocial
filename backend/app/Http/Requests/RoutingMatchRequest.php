<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class RoutingMatchRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Two to eight valid GPS samples are required.';

    public function rules(): array
    {
        return [
            'samples' => ['required', 'array', 'min:2', 'max:8'],
            'samples.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'samples.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'samples.*.accuracy' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'samples.*.timestamp' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:walking,driving,transit'],
        ];
    }
}
