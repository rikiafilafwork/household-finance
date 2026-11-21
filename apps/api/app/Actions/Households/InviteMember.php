<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\InvitationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    /**
     * Undang email ke household. Undangan pending sebelumnya untuk email yang sama dicabut
     * (kirim ulang = token baru). Email dikirim lewat queue setelah commit.
     */
    public function handle(Household $household, User $inviter, string $email, HouseholdRole $role): HouseholdInvitation
    {
        $email = mb_strtolower(trim($email));

        $alreadyMember = $household->members()->where('users.email', $email)->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah menjadi anggota household.']);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($household, $inviter, $email, $role, $token) {
            HouseholdInvitation::query()
                ->where('household_id', $household->getKey())
                ->where('email', $email)
                ->pending()
                ->update(['revoked_at' => now()]);

            return HouseholdInvitation::create([
                'household_id' => $household->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => HouseholdInvitation::hashToken($token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays(HouseholdInvitation::VALID_DAYS),
            ]);
        });

        Notification::route('mail', $email)->notify(new InvitationSent($invitation, $token));

        return $invitation;
    }
}
