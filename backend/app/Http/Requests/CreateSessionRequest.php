<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class CreateSessionRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'E-posta ve şifre zorunludur.';

    protected function apiValidationStatus(): int
    {
        return 422;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email', ''))),
            'password' => (string) $this->input('password', ''),
        ]);
    }

    public function rules(): array
    {
        return array (
  'email' => 
  array (
    0 => 'required',
  ),
  'password' => 
  array (
    0 => 'required',
  ),
);
    }
}
