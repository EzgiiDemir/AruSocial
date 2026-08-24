<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertFoodMenuRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'date is required.';

    public function rules(): array
    {
        return array (
  'date' => 
  array (
    0 => 'required',
  ),
);
    }
}
