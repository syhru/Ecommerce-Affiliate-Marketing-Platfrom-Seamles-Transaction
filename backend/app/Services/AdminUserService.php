<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function create(array $data): User
    {
        $data = $this->editableData($data);
        validator($data, ['role' => ['required', Rule::in(['customer', 'affiliate'])]])->validate();
        return User::create($data);
    }

    public function update(User $record, array $data): User
    {
        return DB::transaction(function () use ($record, $data) {
            // All privileged rows share the same lock order, including ineligible
            // admins. Re-read eligibility after acquiring these locks.
            User::where('role', 'superadmin')->orderBy('id')->lockForUpdate()->get();
            $current = User::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $data = $this->editableData($data);
            $roles = ['customer', 'affiliate'];
            if ($current->isSuperadmin()) {
                $roles[] = 'superadmin';
            }
            validator($data, ['role' => ['sometimes', 'required', Rule::in($roles)]])->validate();

            $field = null;
            if (($data['role'] ?? $current->role) !== 'superadmin') {
                $field = 'role';
            } elseif (! ($data['is_active'] ?? $current->is_active)) {
                $field = 'is_active';
            } elseif (isset($data['email']) && $data['email'] !== $current->email) {
                $field = 'email';
            }
            if ($current->isSuperadmin() && $current->is_active && $current->hasVerifiedEmail() && $field !== null) {
                $otherEligible = User::where('role', 'superadmin')->where('is_active', true)
                    ->whereNotNull('email_verified_at')->whereKeyNot($current->id)->exists();
                if (! $otherEligible) {
                    throw ValidationException::withMessages([$field => 'Operasi ini akan menghilangkan superadmin aktif terverifikasi terakhir.']);
                }
            }
            $current->update($data);
            return $current;
        });
    }

    private function editableData(array $data): array
    {
        return Arr::only($data, ['name', 'email', 'role', 'telegram_chat_id', 'is_active', 'password']);
    }
}
