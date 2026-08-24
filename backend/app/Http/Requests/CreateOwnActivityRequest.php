<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class CreateOwnActivityRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'title and placeId are required.';

    public function rules(): array
    {
        return array (
  'title' => 
  array (
    0 => 'required',
  ),
  'placeId' => 
  array (
    0 => 'required',
  ),
);
    }
}
