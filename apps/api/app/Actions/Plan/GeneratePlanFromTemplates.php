<?php

namespace App\Actions\Plan;

use App\Enums\PlanItemSource;
use App\Enums\PlanMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\User;
use App\Plan\EnvelopeCheck;
use App\Plan\MonthlyDueDate;
use App\Plan\PlanMonth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Isi rencana sebulan dari template aktif (Keputusan #9). Idempoten: dijalankan berapa kali pun,
 * berurutan atau paralel, tiap template paling banyak satu item per bulan.
 *
 *  - Tulis diserialisasi per user (kunci baris user, sama dengan SavePlanTemplate); jaminan
 *    terakhir tetap UNIQUE (template_id, month) dan uq_plan_envelope di plan_items.
 *  - Template tahunan hanya di bulannya; jatuh tempo dari MonthlyDueDate (tanpa drift).
 *  - Dilewati: template yang sudah punya item bulan itu (termasuk hasil "salin" & yang di-skip),
 *    amplop yang kategorinya sudah punya amplop bulan itu, checklist dengan jenis + kategori + nama
 *    sama yang dibuat manual, dan template yang kategorinya sudah diarsip.
 *  - Akun default yang sudah diarsip dikosongkan (item tetap dibuat; dipilih saat centang).
 *  - Item yang dihapus user BISA dibuat ulang oleh panggilan berikutnya; untuk "tidak bulan ini",
 *    pakai skip. Jadwal otomatis hanya jalan sekali di awal bulan (lihat plan:generate).
 */
class GeneratePlanFromTemplates
{
    /**
     * @return int jumlah item yang dibuat
     */
    public function handle(User $user, PlanMonth $month): int
    {
        $created = DB::transaction(function () use ($user, $month) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $templates = PlanTemplate::ownedBy($user)->where('is_active', true)
                ->orderBy('type')->orderBy('mode')->orderByRaw('day_of_month IS NULL, day_of_month')->orderBy('id')
                ->get()
                ->filter(fn (PlanTemplate $t) => MonthlyDueDate::occursIn($month, $t->frequency, $t->month_of_year));
            if ($templates->isEmpty()) {
                return collect();
            }

            $existing = PlanItem::ownedBy($user)->forMonth($month->firstDay())->get();
            $categories = Category::ownedBy($user)->whereKey($templates->pluck('category_id'))->get()->keyBy('id');
            $accounts = Account::ownedBy($user)->whereKey($templates->pluck('account_id')->filter())->get()->keyBy('id');
            $nextSort = (int) $existing->max('sort_order') + 1;

            $made = collect();
            foreach ($templates as $template) {
                $category = $categories->get($template->category_id);
                if ($category === null || $category->archived_at !== null || $this->exists($existing, $template)) {
                    continue;
                }
                $account = $template->account_id !== null ? $accounts->get($template->account_id) : null;

                $item = PlanItem::create([
                    'user_id' => $user->getKey(),
                    'month' => $month->firstDay(),
                    'type' => $template->type,
                    'mode' => $template->mode,
                    'name' => $template->name,
                    'category_id' => $template->category_id,
                    'planned_amount' => $template->planned_amount,
                    'account_id' => $account === null || $account->isArchived() ? null : $account->getKey(),
                    'due_on' => $template->mode === PlanMode::Checklist && $template->day_of_month !== null
                        ? MonthlyDueDate::in($month, $template->day_of_month)
                        : null,
                    'template_id' => $template->getKey(),
                    'source' => PlanItemSource::Template,
                    'sort_order' => $nextSort++,
                ]);
                $existing->push($item);
                $made->push($item);
            }

            return $made;
        });

        // Amplop baru bisa langsung melewati ambang kalau transaksinya sudah ada (job afterCommit).
        $created->each(fn (PlanItem $item) => EnvelopeCheck::afterEnvelopeSaved($item));

        return $created->count();
    }

    /**
     * @param  Collection<int, PlanItem>  $existing
     */
    private function exists(Collection $existing, PlanTemplate $template): bool
    {
        return $existing->contains(function (PlanItem $e) use ($template) {
            if ($e->template_id === $template->getKey()) {
                return true;
            }
            if ($template->mode === PlanMode::Envelope) {
                return $e->mode === PlanMode::Envelope && $e->category_id === $template->category_id;
            }

            return $e->template_id === null && $e->mode === PlanMode::Checklist && $e->type === $template->type
                && $e->category_id === $template->category_id && mb_strtolower($e->name) === mb_strtolower($template->name);
        });
    }
}
