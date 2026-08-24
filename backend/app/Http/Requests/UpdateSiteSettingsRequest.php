<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entra' => ['sometimes', 'array'],
            'entra.tenantId' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'entra.clientId' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'entra.redirectUri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'wordpress' => ['sometimes', 'array'],
            'wordpress.siteUrl' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'wordpress.apiToken' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ];
    }
}
