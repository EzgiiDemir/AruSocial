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
        // The specific message is what the person can act on.
        //
        // This used to return only a fixed summary and throw the validator's
        // messages away, so a request that had gone to the trouble of saying
        // "Fotoğraflar en fazla 12 MB olabilir" produced "Yüklenen dosya
        // kabul edilmedi" instead — true, and useless. The first real message
        // becomes the headline; `fields` carries the rest for a form that
        // wants to mark individual inputs.
        //
        // The `code` stays VALIDATION, so clients branching on it are
        // unaffected.
        $errors = $validator->errors();

        // A per-rule message is only promoted to the headline when the
        // request actually wrote one. Laravel's untranslated default ("The
        // name field is required.") is worse than a curated Turkish summary,
        // so falling back to English everywhere would trade one problem for
        // another — the fix is for requests that took the trouble, not a
        // blanket switch.
        $custom = method_exists($this, 'messages') && $this->messages() !== [];
        $first = $errors->first();

        throw new HttpResponseException(response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => array_filter([
                'code' => 'VALIDATION',
                'message' => $custom && $first !== ''
                    ? $first
                    : $this->apiValidationMessage(),
                // Always present, so a form can mark the offending inputs
                // even when the headline is the generic summary.
                'fields' => $errors->toArray() ?: null,
            ], fn ($v) => $v !== null),
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
