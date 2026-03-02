<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'affiliate';
    }

    public function rules(): array
    {
        return [
            // Jumlah penarikan (minimal Rp 50.000)
            'amount'               => ['required', 'numeric', 'min:50000'],

            // Info rekening bank (sesuai kolom di affiliate_withdrawals)
            'bank_name'            => ['required', 'string', 'max:100'],
            'bank_account_number'  => ['required', 'string', 'max:50'],
            'bank_account_holder'  => ['required', 'string', 'max:255'],

            'notes'                => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'              => 'Jumlah penarikan wajib diisi.',
            'amount.min'                   => 'Minimal penarikan Rp 50.000.',
            'bank_name.required'           => 'Nama bank wajib diisi.',
            'bank_account_number.required' => 'Nomor rekening wajib diisi.',
            'bank_account_holder.required' => 'Nama pemilik rekening wajib diisi.',
        ];
    }
}
