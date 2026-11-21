<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcceptInvitation
{
    /**
     * Token dipakai sekali. Baris undangan dikunci (lockForUpdate) supaya dua accept paralel
     * atas token yang sama tersusun serial: yang kedua melihat accepted_at dan ditolak.
     */
    public function handle(User $user, string $token): Household
    {
        return DB::transaction(function () use ($user, $token) {
            $invitation = HouseholdInvitation::query()
                ->where('token_hash', HouseholdInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new NotFoundHttpException('Undangan tidak ditemukan.');
            }

            $this->ensureUsable($invitation);

            if (mb_strtolower($user->email) !== $invitation->email) {
                throw new AccessDeniedHttpException("Undangan ini untuk {$invitation->email}. Masuk dengan email tersebut untuk menerimanya.");
            }

            $household = $invitation->household;

            if (! $household->hasMember($user)) {
                $household->members()->attach($user->getKey(), [
                    'role' => $invitation->role,
                    'joined_at' => now(),
                ]);
            }

            $invitation->update(['accepted_at' => now()]);

            return $household;
        });
    }

    public function ensureUsable(HouseholdInvitation $invitation): void
    {
        $reason = match (true) {
            $invitation->accepted_at !== null => 'Undangan ini sudah dipakai.',
            $invitation->revoked_at !== null => 'Undangan ini sudah dicabut.',
            $invitation->expires_at->isPast() => 'Undangan ini sudah kedaluwarsa. Minta owner mengirim ulang.',
            default => null,
        };

        if ($reason !== null) {
            throw new GoneHttpException($reason);
        }
    }
}
