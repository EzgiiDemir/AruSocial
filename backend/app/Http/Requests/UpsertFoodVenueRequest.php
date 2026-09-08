<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertFoodVenueRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and name are required.';

    public function rules(): array
    {
        return [
            'id' => ['required'],
            'name' => ['required'],
            'hours' => ['nullable', 'string', 'max:255'],
            // The menu is usually a few typed lines. A linked file stays
            // supported for venues that publish a PDF, but is no longer
            // the only way to describe what is being served.
            'menuText' => ['nullable', 'string', 'max:5000'],
            'menuFileUrl' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
