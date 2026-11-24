<?php

namespace App\Actions\Plan;

use App\Actions\Accounts\LockAccounts;
use App\Enums\PlanItemType;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Centang = membuat transaksi, dalam SATU transaksi DB (Keputusan #8).
 *
 * Urutan kunci tetap: item → akun (UncheckPlanItem memakai urutan yang sama), jadi tidak deadlock.
 * client_id dicek SETELAH terkunci: retry paralel jadi serial dan dijawab 200 (Keputusan #15).
 * Centang kedua dengan client_id lain (perangkat lain) → 409. UNIQUE (plan_item_id) jaring terakhir.
 */
class CheckPlanItem
{
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /**
     * @return array{0: Transaction, 1: bool} [transaksi, baru dibuat?]
     */
    public function handle(User $user, PlanItem $item, CheckPlanItemInput $input): array
    {
        try {
            return $this->check($user, $item, $input);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $input->clientId !== null
                ? Transaction::ownedBy($user)->where('client_id', $input->clientId)->first()
                : null;
            if ($existing !== null && $existing->plan_item_id === $item->getKey()) {
                return [$existing, false];
            }
            if (str_contains($e->getMessage(), 'uq_tx_plan_item')) {
                throw self::alreadyDone();
            }
            throw $e;
        }
    }

    /**
     * @return array{0: Transaction, 1: bool}
     */
    private function check(User $user, PlanItem $item, CheckPlanItemInput $input): array
    {
        return DB::transaction(function () use ($user, $item, $input) {
            /** @var PlanItem $locked */
            $locked = PlanItem::ownedBy($user)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            $accountId = $input->accountId ?? $locked->account_id;
            if ($accountId === null) {
                throw ValidationException::withMessages(['account_id' => 'Pilih akun: item ini belum punya akun default.']);
            }
            $account = $this->lockAccounts->one($user, $accountId);

            // Tanpa client_id (auto-post) tidak ada retry klien untuk dicocokkan.
            $existing = $input->clientId !== null
                ? Transaction::ownedBy($user)->where('client_id', $input->clientId)->first()
                : null;
            if ($existing !== null) {
                if ($existing->plan_item_id !== $locked->getKey()) {
                    throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
                }

                return [$existing, false];
            }

            if (! $locked->isChecklist()) {
                throw ValidationException::withMessages(['mode' => 'Hanya item checklist yang bisa dicentang. Amplop terisi dari transaksi kategorinya.']);
            }
            if ($locked->isSkipped()) {
                throw new ConflictHttpException('Item ini sedang dilewati bulan ini. Batalkan lewati dulu.');
            }
            if ($locked->isDone()) {
                throw self::alreadyDone();
            }
            if ($account->isArchived()) {
                throw ValidationException::withMessages(['account_id' => 'Akun ini sudah diarsip.']);
            }

            $magnitude = abs($input->amount ?? $locked->planned_amount);
            $transaction = Transaction::create([
                'user_id' => $user->getKey(),
                'account_id' => $account->getKey(),
                'category_id' => $locked->category_id,
                'type' => $locked->type->transactionType(),
                'amount' => $locked->type === PlanItemType::Income ? $magnitude : -$magnitude,
                'occurred_on' => $input->occurredOn ?? CarbonImmutable::now($user->timezone)->toDateString(),
                'note' => $input->note ?? $locked->name,
                'client_id' => $input->clientId,
                'plan_item_id' => $locked->getKey(),
            ]);

            $account->increment('balance', $transaction->amount);
            if ($input->autoPost) {
                $locked->forceFill(['auto_posted_at' => now()])->save();
            }

            return [$transaction, true];
        });
    }

    private static function alreadyDone(): ConflictHttpException
    {
        return new ConflictHttpException('Item ini sudah dicentang (mungkin dari perangkat lain).');
    }
}
