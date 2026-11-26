<?php

namespace App\Actions\Savings;

use App\Actions\Accounts\LockAccounts;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah (amount > 0) atau kurangi (amount < 0) alokasi satu tujuan. Saldo rekening tidak berubah;
 * alokasi hanya label di atasnya, jadi Σ alokasi rekening tidak boleh melebihi saldonya saat ditambah.
 */
class AllocateSavings
{
    public function __construct(
        private readonly LockAccounts $lockAccounts,
        private readonly LockSavingsGoals $lockGoals,
    ) {}

    /**
     * @return array{0: SavingsAllocation, 1: bool} [baris log, baru dibuat?]
     */
    public function handle(User $user, SavingsGoal $goal, int $amount, string $occurredOn, ?string $note, ?string $clientId): array
    {
        try {
            return $this->allocate($user, $goal, $amount, $occurredOn, $note, $clientId);
        } catch (UniqueConstraintViolationException $e) {
            // Retry dengan client_id sama ke tujuan di rekening LAIN tidak terserialisasi kunci rekening;
            // unique index menolak yang kalah → kembalikan baris pemenang.
            $existing = self::existingByClientId($user, $clientId);

            if ($existing === null) {
                throw $e;
            }

            return [self::assertPlainAllocation($existing), false];
        }
    }

    /**
     * @return array{0: SavingsAllocation, 1: bool}
     */
    private function allocate(User $user, SavingsGoal $goal, int $amount, string $occurredOn, ?string $note, ?string $clientId): array
    {
        return DB::transaction(function () use ($user, $goal, $amount, $occurredOn, $note, $clientId) {
            // Kunci rekening dulu: semua perubahan alokasi di rekening ini jadi serial, jadi Σ alokasi
            // yang dibaca di bawah tidak bisa basi walau tujuannya berbeda.
            $account = $this->lockAccounts->one($user, $goal->account_id);
            /** @var SavingsGoal $locked */
            $locked = $this->lockGoals->handle($user, [$goal->getKey()])->first();

            // Idempotensi: dicek SETELAH terkunci → retry paralel jadi serial.
            $existing = self::existingByClientId($user, $clientId);
            if ($existing !== null) {
                return [self::assertPlainAllocation($existing), false];
            }

            if ($amount === 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal tidak boleh 0.']);
            }
            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['goal' => 'Tujuan ini sudah diarsip.']);
            }
            if (! $account->is_savings || $account->isArchived()) {
                throw ValidationException::withMessages(['goal' => 'Rekening tujuan ini bukan rekening tabungan aktif.']);
            }

            if ($amount > 0) {
                // Σ semua tujuan di rekening (tujuan arsip selalu 0).
                $allocated = (int) SavingsGoal::ownedBy($user)->where('account_id', $account->getKey())->sum('allocated_amount');
                $unallocated = $account->balance - $allocated;

                if ($amount > $unallocated) {
                    throw ValidationException::withMessages([
                        'amount' => 'Melebihi saldo yang belum dialokasikan ('.Money::format(max(0, $unallocated)).')',
                    ]);
                }
            } elseif ($locked->allocated_amount + $amount < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Melebihi alokasi tujuan ini ('.Money::format($locked->allocated_amount).')',
                ]);
            }

            $allocation = SavingsAllocation::create([
                'user_id' => $user->getKey(),
                'savings_goal_id' => $locked->getKey(),
                'amount' => $amount,
                'occurred_on' => $occurredOn,
                'note' => $note,
                'client_id' => $clientId,
            ]);

            $locked->increment('allocated_amount', $amount);

            return [$allocation, true];
        });
    }

    /** Dicari per user: client_id milik user lain tidak pernah cocok (sama dengan uuid baru). */
    public static function existingByClientId(User $user, ?string $clientId): ?SavingsAllocation
    {
        if ($clientId === null) {
            return null;
        }

        return SavingsAllocation::ownedBy($user)->where('client_id', $clientId)->first();
    }

    private static function assertPlainAllocation(SavingsAllocation $existing): SavingsAllocation
    {
        if ($existing->move_group_id !== null) {
            throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai pemindahan alokasi.']);
        }

        return $existing;
    }
}
