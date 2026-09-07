<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Services\CareerCvStorage;
use Illuminate\Foundation\Http\FormRequest;

class StoreCareerCvRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'A PDF or Word CV file is required (max 8MB).';

    public function rules(): array
    {
        $mimes = implode(',', CareerCvStorage::EXTENSIONS);

        return [
            'file' => ['required', 'file', 'max:8192', 'mimes:'.$mimes],
        ];
    }
}
