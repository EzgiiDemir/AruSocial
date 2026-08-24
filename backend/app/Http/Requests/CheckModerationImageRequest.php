<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class CheckModerationImageRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'imageBase64 is required.';

    public function rules(): array
    {
        return array (
  'imageBase64' => 
  array (
    0 => 'required',
  ),
);
    }
}
