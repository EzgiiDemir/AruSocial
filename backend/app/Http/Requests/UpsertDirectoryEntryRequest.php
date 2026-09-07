<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertDirectoryEntryRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, building and occupantName are required.';

    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'building' => ['required', 'string'],
            'occupantName' => ['required', 'string'],
            'tourUrl' => ['nullable', 'url:http,https', 'max:1000'],
            'floor' => ['nullable', 'string', 'max:200'],
            'room' => ['nullable', 'string', 'max:200'],
            'occupantRole' => ['nullable', 'string', 'max:200'],
            'relatedServiceId' => ['nullable', 'string', 'exists:services,id'],
            'tourTarget' => ['nullable', 'string', 'max:500'],
        ];
    }
}
