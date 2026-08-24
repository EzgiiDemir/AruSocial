<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpsertDirectoryEntryRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'id, building and occupantName are required.';

    public function rules(): array
    {
        return array (
  'id' => 
  array (
    0 => 'required',
  ),
  'building' => 
  array (
    0 => 'required',
  ),
  'occupantName' => 
  array (
    0 => 'required',
  ),
);
    }
}
