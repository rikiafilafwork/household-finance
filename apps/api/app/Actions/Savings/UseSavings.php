<?php

namespace App\Actions\Savings;

use App\Actions\Accounts\LockAccounts;
use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\TransactionInput;
use App\Enums\TransactionType;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Gunakan tabungan": pengeluaran dari rekening tabungan + log alokasi negatif yang menunjuk
 * transaksi itu, dalam satu transaksi DB. Saldo dan alokasi turun bersamaan → tidak terpakai.
 * client_id disimpan di transaksi; log dicari lewat transaction_id.
 */
class UseSavings
{
    public function __construct(
        private readonly LockAccounts $lockAccounts,
        private readonly LockSavingsGoals $lockGoals,
        private readonly CreateTransaction $createTransaction,
    ) {}

    /**
     * @return array{0: Transaction, 1: bool} [transaksi, baru dibuat?]
     */
    public function handle(User $user, SavingsGoal $goal, int $amount, int $categoryId, string $occurredOn, ?string $note, string $clientId): array
    {
        return DB::transaction(function () use ($user, $goal, $amount, $categoryId, $occurredOn, $note, $clientId) {
            // Urutan kunci: rekening → tujuan, sama dengan semua penulis allocated_amount.
            $this->lockAccounts->one($user, $goal->account_id);
            /** @var SavingsGoal $locked */
            $locked = $this->lockGoals->handle($user, [$goal->getKey()])->first();

            // Idempotensi: dicek SETELAH terkunci → retry paralel jadi serial.
            $existing = Transaction::ownedBy($user)->where('client_id', $clientId)->first();
            if ($existing !== null) {
                return [self::assertLinkedTo($existing, $locked), false];
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
            }
            if ($amount > $locked->allocated_amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Melebihi alokasi tujuan ini ('.Money::format($locked->allocated_amount).')',
                ]);
            }

            [$transaction, $created] = $this->createTransaction->handle($user, new TransactionInput(
                accountId: $locked->account_id,
                type: TransactionType::Expense,
                amount: $amount,
                occurredOn: $occurredOn,
                categoryId: $categoryId,
                note: $note,
                clientId: $clientId,
            ));

            // Kalah balapan client_id dengan transaksi di rekening lain (unique index).
            if (! $created) {
                return [self::assertLinkedTo($transaction, $locked), false];
            }

            $allocation = SavingsAllocation::create([
                'user_id' => $user->getKey(),
                'savings_goal_id' => $locked->getKey(),
                'amount' => $transaction->amount, // = -amount
                'occurred_on' => $occurredOn,
                'note' => $note,
                'transaction_id' => $transaction->getKey(),
            ]);

            $locked->decrement('allocated_amount', $amount);

            return [$transaction->setRelation('savingsAllocation', $allocation), true];
        });
    }

    /** Replay sah hanya kalau transaksi lama memang "Gunakan" untuk tujuan ini. */
    private static function assertLinkedTo(Transaction $existing, SavingsGoal $goal): Transaction
    {
        $allocation = $existing->savingsAllocation;

        if ($allocation === null || $allocation->savings_goal_id !== $goal->getKey()) {
            throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
        }

        return $existing;
    }
}
