<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertAdminEventRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id and title are required.';

    public function rules(): array
    {
        return array (
  'id' => 
  array (
    0 => 'required',
  ),
  'title' => 
  array (
    0 => 'required',
  ),
);
    }
}
