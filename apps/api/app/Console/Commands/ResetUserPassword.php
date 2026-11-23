<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Lupa password tanpa email (Keputusan #5): dijalankan pemilik server.
 * Semua sesi web dan token mobile user itu dicabut supaya perangkat lama harus login ulang.
 */
class ResetUserPassword extends Command
{
    protected $description = 'Set password baru untuk user (tanpa email); cabut semua sesi dan token';

    protected $signature = 'user:reset-password
        {email : Email user}
        {--password= : Password baru (kalau kosong, ditanyakan secara tersembunyi)}';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("User dengan email {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            $password = (string) $this->secret('Password baru');
            if ($password !== (string) $this->secret('Ulangi password baru')) {
                $this->error('Password tidak sama.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        });

        $this->info("Password {$user->email} sudah diganti. Semua sesi dan token lama dicabut.");

        return self::SUCCESS;
    }
}
