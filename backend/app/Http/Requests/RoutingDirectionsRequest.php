<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class RoutingDirectionsRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'fromLat, fromLng, toLat, and toLng must be numeric coordinates.';

    public function rules(): array
    {
        return [
            'fromLat' => ['required', 'numeric'],
            'fromLng' => ['required', 'numeric'],
            'toLat' => ['required', 'numeric'],
            'toLng' => ['required', 'numeric'],
        ];
    }
}
