<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Models\ShuttleRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertShuttleRouteRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, name, stops and departures are required.';

    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'colorKey' => ['nullable', 'string', Rule::in(ShuttleRoute::COLOR_KEYS)],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*' => ['string'],
            'departures' => ['required', 'array', 'min:1'],
            'departures.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'returns' => ['nullable', 'array'],
            'returns.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'sortOrder' => ['nullable', 'integer'],
        ];
    }
}
