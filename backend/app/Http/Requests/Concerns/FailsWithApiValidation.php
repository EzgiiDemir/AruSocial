<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

trait FailsWithApiValidation
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => [
                'code' => 'VALIDATION',
                'message' => $this->apiValidationMessage(),
            ],
        ], $this->apiValidationStatus()));
    }

    protected function apiValidationStatus(): int
    {
        return 400;
    }

    protected function apiValidationMessage(): string
    {
        return $this->validationMessage ?? 'Validation failed.';
    }
}
