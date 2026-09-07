<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Models\OnboardingStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertOnboardingStepRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, groupLabel, title and detail are required.';

    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'groupLabel' => ['required', 'string'],
            'title' => ['required', 'string'],
            'detail' => ['required', 'string'],
            'actionKind' => ['nullable', 'string', Rule::in(OnboardingStep::ACTION_KINDS)],
            'refId' => ['nullable', 'string'],
            'sortOrder' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
