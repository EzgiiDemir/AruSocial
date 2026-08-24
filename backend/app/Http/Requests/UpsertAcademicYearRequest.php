<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertAcademicYearRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, label, startsOn and endsOn are required.';

    public function rules(): array
    {
        return array (
  'id' => 
  array (
    0 => 'required',
  ),
  'label' => 
  array (
    0 => 'required',
  ),
  'startsOn' => 
  array (
    0 => 'required',
  ),
  'endsOn' => 
  array (
    0 => 'required',
  ),
);
    }
}
