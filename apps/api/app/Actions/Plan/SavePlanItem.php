<?php

namespace App\Actions\Plan;

use App\Enums\CategoryKind;
use App\Enums\PlanItemSource;
use App\Enums\PlanMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\User;
use App\Plan\PlanMonth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah / ubah item rencana. Item yang sudah dicentang hanya boleh diubah nama, nominal
 * rencana, akun default, jatuh tempo, dan urutan: jenis/mode/kategorinya sudah "dipakai"
 * oleh transaksi centangnya.
 */
class SavePlanItem
{
    public function create(User $user, PlanMonth $month, PlanItemInput $input): PlanItem
    {
        $this->assertValid($user, $month, $input);

        return $this->guardEnvelope(fn () => PlanItem::create([
            'user_id' => $user->getKey(),
            'month' => $month->firstDay(),
            'type' => $input->type,
            'mode' => $input->mode,
            'name' => $input->name,
            'category_id' => $input->categoryId,
            'planned_amount' => $input->plannedAmount,
            'account_id' => $input->accountId,
            'due_on' => $input->mode === PlanMode::Checklist ? $input->dueOn : null,
            'source' => PlanItemSource::Manual,
            'sort_order' => $input->sortOrder,
        ]));
    }

    public function update(User $user, PlanItem $item, PlanItemInput $input): PlanItem
    {
        return DB::transaction(function () use ($user, $item, $input) {
            /** @var PlanItem $locked */
            $locked = PlanItem::ownedBy($user)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $month = PlanMonth::fromDate($locked->month);

            if ($locked->isDone()) {
                $changed = array_filter([
                    'type' => $input->type !== $locked->type,
                    'mode' => $input->mode !== $locked->mode,
                    'category_id' => $input->categoryId !== $locked->category_id,
                ]);
                if ($changed !== []) {
                    throw ValidationException::withMessages(array_map(
                        fn () => 'Item ini sudah dicentang. Batalkan centang dulu untuk mengubah jenis, mode, atau kategorinya.',
                        $changed,
                    ));
                }
            }

            $this->assertValid($user, $month, $input, keepArchivedCategoryId: $locked->category_id, keepArchivedAccountId: $locked->account_id);

            return $this->guardEnvelope(function () use ($locked, $input) {
                $locked->fill([
                    'type' => $input->type,
                    'mode' => $input->mode,
                    'name' => $input->name,
                    'category_id' => $input->categoryId,
                    'planned_amount' => $input->plannedAmount,
                    'account_id' => $input->accountId,
                    'due_on' => $input->mode === PlanMode::Checklist ? $input->dueOn : null,
                    'sort_order' => $input->sortOrder,
                ])->save();

                return $locked;
            });
        });
    }

    private function assertValid(User $user, PlanMonth $month, PlanItemInput $input, ?int $keepArchivedCategoryId = null, ?int $keepArchivedAccountId = null): void
    {
        $category = Category::ownedBy($user)->whereKey($input->categoryId)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Kategori tidak ditemukan.']);
        }
        if ($category->kind !== CategoryKind::from($input->type->value)) {
            throw ValidationException::withMessages(['category_id' => 'Jenis kategori tidak cocok dengan jenis item.']);
        }
        if ($category->archived_at !== null && $category->getKey() !== $keepArchivedCategoryId) {
            throw ValidationException::withMessages(['category_id' => 'Kategori ini sudah diarsip.']);
        }

        if ($input->accountId !== null) {
            $account = Account::ownedBy($user)->whereKey($input->accountId)->first();
            if ($account === null) {
                throw ValidationException::withMessages(['account_id' => 'Akun tidak ditemukan.']);
            }
            if ($account->isArchived() && $account->getKey() !== $keepArchivedAccountId) {
                throw ValidationException::withMessages(['account_id' => 'Akun ini sudah diarsip.']);
            }
        }

        if ($input->mode === PlanMode::Checklist && $input->dueOn !== null && ! $month->contains($input->dueOn)) {
            throw ValidationException::withMessages(['due_on' => 'Jatuh tempo harus di bulan rencana ini.']);
        }
        if ($input->mode === PlanMode::Envelope && $input->dueOn !== null) {
            throw ValidationException::withMessages(['due_on' => 'Amplop tidak punya jatuh tempo.']);
        }
    }

    /**
     * Satu amplop per kategori per bulan dijaga unique index uq_plan_envelope (generated column);
     * pelanggaran (termasuk dua request bersamaan) dijawab 422, bukan 500.
     *
     * @param  callable(): PlanItem  $write
     */
    private function guardEnvelope(callable $write): PlanItem
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'uq_plan_envelope')) {
                throw $e;
            }
            throw ValidationException::withMessages(['category_id' => 'Bulan ini sudah punya amplop untuk kategori tersebut.']);
        }
    }
}
