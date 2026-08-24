<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertParticipationTypeRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'label is required.';

    public function rules(): array
    {
        return array (
  'label' => 
  array (
    0 => 'required',
  ),
);
    }
}
