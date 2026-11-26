<?php

namespace App\Actions\Savings;

use App\Actions\Accounts\LockAccounts;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pindah alokasi antar tujuan di rekening yang sama: dua baris log berpasangan (move_group_id sama)
 * dalam satu transaksi DB. Σ alokasi rekening tidak berubah, jadi saldo tidak dicek — tetap boleh
 * saat rekening terpakai (justru dipakai untuk merapikan alokasi).
 */
class MoveSavingsAllocation
{
    public function __construct(
        private readonly LockAccounts $lockAccounts,
        private readonly LockSavingsGoals $lockGoals,
    ) {}

    /**
     * @return array{0: SavingsAllocation, 1: bool} [baris keluar, baru dibuat?]
     */
    public function handle(User $user, SavingsGoal $from, SavingsGoal $to, int $amount, ?string $note, ?string $clientId): array
    {
        try {
            return $this->move($user, $from, $to, $amount, $note, $clientId);
        } catch (UniqueConstraintViolationException $e) {
            $existing = AllocateSavings::existingByClientId($user, $clientId);

            if ($existing === null) {
                throw $e;
            }

            return [self::assertMove($existing), false];
        }
    }

    /**
     * @return array{0: SavingsAllocation, 1: bool}
     */
    private function move(User $user, SavingsGoal $from, SavingsGoal $to, int $amount, ?string $note, ?string $clientId): array
    {
        return DB::transaction(function () use ($user, $from, $to, $amount, $note, $clientId) {
            // Urutan kunci: rekening → tujuan (id menaik). Rekening tujuan dikunci walau sama, supaya
            // pindah tidak balapan dengan tambah alokasi / arsip di rekening itu.
            $this->lockAccounts->handle($user, [$from->account_id, $to->account_id]);
            $goals = $this->lockGoals->handle($user, [$from->getKey(), $to->getKey()]);
            /** @var SavingsGoal $source */
            $source = $goals->get($from->getKey());
            /** @var SavingsGoal $target */
            $target = $goals->get($to->getKey());

            $existing = AllocateSavings::existingByClientId($user, $clientId);
            if ($existing !== null) {
                return [self::assertMove($existing), false];
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
            }
            if ($source->is($target)) {
                throw ValidationException::withMessages(['to_goal_id' => 'Pilih tujuan lain.']);
            }
            if ($source->account_id !== $target->account_id) {
                throw ValidationException::withMessages(['to_goal_id' => 'Tujuan harus di rekening tabungan yang sama.']);
            }
            if ($target->isArchived()) {
                throw ValidationException::withMessages(['to_goal_id' => 'Tujuan ini sudah diarsip.']);
            }
            if ($source->allocated_amount < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Melebihi alokasi tujuan asal ('.Money::format($source->allocated_amount).')',
                ]);
            }

            $groupId = (string) Str::uuid();
            $today = CarbonImmutable::now($user->timezone)->toDateString();
            $row = fn (SavingsGoal $goal, int $signed, ?string $clientId) => SavingsAllocation::create([
                'user_id' => $user->getKey(),
                'savings_goal_id' => $goal->getKey(),
                'amount' => $signed,
                'occurred_on' => $today,
                'note' => $note,
                'move_group_id' => $groupId,
                'client_id' => $clientId, // hanya baris keluar: unik per user
            ]);

            $out = $row($source, -$amount, $clientId);
            $row($target, $amount, null);

            $source->decrement('allocated_amount', $amount);
            $target->increment('allocated_amount', $amount);

            return [$out, true];
        });
    }

    private static function assertMove(SavingsAllocation $existing): SavingsAllocation
    {
        if ($existing->move_group_id === null) {
            throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai sebuah alokasi.']);
        }

        return $existing;
    }
}
