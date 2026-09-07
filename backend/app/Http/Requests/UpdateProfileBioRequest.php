<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileBioRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid profile fields.';

    public function rules(): array
    {
        return [
            'department' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'string', 'max:60'],
            'university' => ['nullable', 'string', 'max:160'],
            'avatarUrl' => ['nullable', 'string', 'max:500'],
            'clubs' => ['nullable', 'array'],
            'clubs.*' => ['string', 'max:160'],
            'achievements' => ['nullable', 'array'],
            'achievements.*' => ['string', 'max:160'],
            'projects' => ['nullable', 'array'],
            'projects.*' => ['string', 'max:160'],
        ];
    }
}
