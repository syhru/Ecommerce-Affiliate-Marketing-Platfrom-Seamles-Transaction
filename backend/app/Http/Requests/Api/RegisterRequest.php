<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
      'name'             => ['required', 'string', 'max:255'],
      'email'            => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
      'password'         => ['required', 'confirmed', Password::min(8)],
      'role'             => ['sometimes', 'string', 'in:customer,affiliate'],
      'telegram_chat_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
      'name.required'           => 'Nama wajib diisi.',
      'email.required'          => 'Email wajib diisi.',
      'email.unique'            => 'Email sudah terdaftar.',
      'password.required'       => 'Password wajib diisi.',
      'password.confirmed'      => 'Konfirmasi password tidak cocok.',
      'role.in'                 => 'Role tidak valid. Pilih customer atau affiliate.',
      'telegram_chat_id.max'    => 'Telegram Chat ID maksimal 100 karakter.',
        ];
    }
}
