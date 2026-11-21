<?php

namespace App\Actions\Transfers;

use App\Actions\Transactions\ValidatesTransactionTarget;
use App\Actions\Wallets\LockWallets;
use App\Enums\TransactionType;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transfer = 2 baris berpasangan (Keputusan #4): keluar di wallet asal (negatif),
 * masuk di wallet tujuan (positif), category NULL. client_id dipasang di baris keluar.
 */
class CreateTransfer
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockWallets $lockWallets) {}

    /**
     * @return array{0: TransferPair, 1: bool} [transfer, baru dibuat?]
     */
    public function handle(Household $household, User $actor, TransferInput $input): array
    {
        if ($input->fromWalletId === $input->toWalletId) {
            throw ValidationException::withMessages(['to_wallet_id' => 'Wallet asal dan tujuan harus berbeda.']);
        }

        try {
            return $this->create($household, $actor, $input);
        } catch (UniqueConstraintViolationException $e) {
            // Lihat CreateTransaction: retry ber-client_id sama ke pasangan wallet berbeda.
            $existing = $input->clientId === null ? null : Transaction::query()
                ->where('household_id', $household->getKey())
                ->where('client_id', $input->clientId)
                ->first();

            if ($existing === null) {
                throw $e;
            }
            if ($existing->transfer_group_id === null) {
                throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
            }

            return [TransferPair::find($household, $existing->transfer_group_id), false];
        }
    }

    /**
     * @return array{0: TransferPair, 1: bool}
     */
    private function create(Household $household, User $actor, TransferInput $input): array
    {
        return DB::transaction(function () use ($household, $actor, $input) {
            $wallets = $this->lockWallets->handle([$input->fromWalletId, $input->toWalletId]);

            if ($input->clientId !== null) {
                $existing = Transaction::query()
                    ->where('household_id', $household->getKey())
                    ->where('client_id', $input->clientId)
                    ->first();

                if ($existing !== null && $existing->transfer_group_id !== null) {
                    return [TransferPair::find($household, $existing->transfer_group_id), false];
                }
                if ($existing !== null) {
                    throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
                }
            }

            $from = $wallets->get($input->fromWalletId);
            $to = $wallets->get($input->toWalletId);
            assert($from !== null && $to !== null);

            $this->assertWalletWritable($from, $household, $actor, 'from_wallet_id');
            $this->assertWalletWritable($to, $household, $actor, 'to_wallet_id');

            $groupId = (string) Str::uuid();
            $base = [
                'household_id' => $household->getKey(),
                'type' => TransactionType::Transfer,
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
                'transfer_group_id' => $groupId,
                'created_by' => $actor->getKey(),
            ];

            $out = Transaction::create($base + [
                'wallet_id' => $from->getKey(),
                'amount' => -abs($input->amount),
                'client_id' => $input->clientId,
            ]);
            $in = Transaction::create($base + [
                'wallet_id' => $to->getKey(),
                'amount' => abs($input->amount),
            ]);

            $from->increment('balance', $out->amount);
            $to->increment('balance', $in->amount);

            return [TransferPair::find($household, $groupId), true];
        });
    }
}
