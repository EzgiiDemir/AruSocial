<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertClubRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and name are required.';

    public function rules(): array
    {
        return array (
  'id' => 
  array (
    0 => 'required',
  ),
  'name' => 
  array (
    0 => 'required',
  ),
);
    }
}
