<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserSettingsRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Invalid settings.';

    public function rules(): array
    {
        return [
            'locationVisibility' => ['sometimes', 'string', 'in:ghost,friends,community,public'],
            'nearbyDiscoverable' => ['sometimes', 'boolean'],
            'checkInVisible' => ['sometimes', 'boolean'],
            'personalization' => ['sometimes', 'boolean'],
            'isPrivateProfile' => ['sometimes', 'boolean'],
            'preferredLanguage' => ['sometimes', 'string', 'in:TR,EN,RU'],
        ];
    }
}
