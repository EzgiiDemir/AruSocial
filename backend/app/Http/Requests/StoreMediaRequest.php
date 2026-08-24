<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'A valid file upload is required.';

    public function rules(): array
    {
        return array (
  'file' => 
  array (
    0 => 'required',
    1 => 'file',
  ),
);
    }
}
