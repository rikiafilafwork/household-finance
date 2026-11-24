<?php

namespace App\Actions\Plan;

use App\Enums\PlanItemSource;
use App\Enums\PlanMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\User;
use App\Plan\MonthlyDueDate;
use App\Plan\PlanMonth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Salin dari bulan lalu": hanya item yang BELUM ada di bulan tujuan, jadi aman diklik berkali-kali.
 *  - amplop: sudah ada kalau bulan tujuan punya amplop kategori yang sama;
 *  - checklist dari template: sudah ada kalau template yang sama sudah ada di bulan tujuan;
 *  - checklist manual: sudah ada kalau ada item dengan jenis + kategori + nama yang sama.
 * Item yang di-skip bulan lalu tetap disalin (bulan ini mungkin ada lagi), tanpa status skip.
 * Kategori/akun yang sudah diarsip tidak disalin / akun default dikosongkan.
 * Jatuh tempo item dari template dihitung dari `day_of_month` template (tanpa drift: 31 → 28 Feb
 * → 31 Mar); item manual memakai tanggal bulan lalu.
 */
class CopyPlanFromPreviousMonth
{
    /**
     * @return int jumlah item yang dibuat
     */
    public function handle(User $user, PlanMonth $target): int
    {
        return DB::transaction(function () use ($user, $target) {
            // Kunci baris bulan tujuan supaya dua klik bersamaan tidak menyalin dua kali.
            $existing = PlanItem::ownedBy($user)->forMonth($target->firstDay())->lockForUpdate()->get();
            $source = PlanItem::ownedBy($user)->forMonth($target->previous()->firstDay())
                ->orderBy('sort_order')->orderBy('id')
                ->get();
            // Eksplisit per user (bukan relasi ber-scope) supaya bisa jalan dari console/job.
            $categories = Category::ownedBy($user)->whereKey($source->pluck('category_id'))->get()->keyBy('id');
            $accounts = Account::ownedBy($user)->whereKey($source->pluck('account_id')->filter())->get()->keyBy('id');
            $templateDays = PlanTemplate::ownedBy($user)->whereKey($source->pluck('template_id')->filter())->pluck('day_of_month', 'id');

            $created = 0;
            foreach ($source as $item) {
                $category = $categories->get($item->category_id);
                $account = $item->account_id !== null ? $accounts->get($item->account_id) : null;
                if ($category === null || $category->archived_at !== null || $this->exists($existing, $item)) {
                    continue;
                }

                $copy = PlanItem::create([
                    'user_id' => $user->getKey(),
                    'month' => $target->firstDay(),
                    'type' => $item->type,
                    'mode' => $item->mode,
                    'name' => $item->name,
                    'category_id' => $item->category_id,
                    'planned_amount' => $item->planned_amount,
                    'account_id' => $account === null || $account->isArchived() ? null : $account->getKey(),
                    'due_on' => $this->dueOn($item, $target, $templateDays->get((int) $item->template_id)),
                    'template_id' => $item->template_id,
                    'source' => PlanItemSource::Copied,
                    'sort_order' => $item->sort_order,
                ]);
                $existing->push($copy);
                $created++;
            }

            return $created;
        });
    }

    private function dueOn(PlanItem $item, PlanMonth $target, ?int $templateDay): ?string
    {
        if ($item->due_on === null) {
            return null;
        }

        return MonthlyDueDate::in($target, $templateDay ?? $item->due_on->day);
    }

    /**
     * @param  Collection<int, PlanItem>  $existing
     */
    private function exists($existing, PlanItem $item): bool
    {
        return $existing->contains(function (PlanItem $e) use ($item) {
            if ($item->mode === PlanMode::Envelope) {
                return $e->mode === PlanMode::Envelope && $e->category_id === $item->category_id;
            }
            if ($item->template_id !== null) {
                return $e->template_id === $item->template_id;
            }

            return $e->mode === PlanMode::Checklist && $e->type === $item->type
                && $e->category_id === $item->category_id && mb_strtolower($e->name) === mb_strtolower($item->name);
        });
    }
}
