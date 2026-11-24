<?php

namespace App\Actions\Plan;

use App\Enums\PlanMode;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\User;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * plan:auto-post (Keputusan #9): item checklist dari template `auto_post` yang sudah jatuh tempo
 * dicentang otomatis lewat CheckPlanItem yang SAMA dengan centang manual (saldo, alert, kunci).
 *
 *  - Jatuh tempo = `due_on` ≤ hari ini di zona waktu user. Jendela susulan: sejak tanggal 1 bulan
 *    lalu (server mati beberapa hari tetap tersusul; mengaktifkan auto_post tidak memposting
 *    tagihan lama berbulan-bulan).
 *  - Tanggal transaksi = `due_on` (hari autodebet/gaji), nominal = rencana, akun = akun default item.
 *  - Sekali per item (`auto_posted_at`): yang sudah dicentang manual, di-skip, atau pernah di-auto-post
 *    lalu dibatalkan user tidak disentuh lagi. Dijalankan 2x / paralel → tetap 1 transaksi
 *    (UNIQUE plan_item_id + kunci item di CheckPlanItem).
 *  - Template yang auto_post-nya dimatikan berhenti memposting item yang belum jatuh tempo.
 */
class AutoPostDueItems
{
    public function __construct(private readonly CheckPlanItem $check) {}

    /**
     * @return array{posted: list<int>, skipped: array<int, string>} id item → alasan dilewati
     */
    public function handle(User $user, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($user->timezone)->toDateString();
        $from = PlanMonth::fromDate(CarbonImmutable::parse($today))->previous()->firstDay();

        $autoTemplates = PlanTemplate::ownedBy($user)->where('auto_post', true)->pluck('id');
        if ($autoTemplates->isEmpty()) {
            return ['posted' => [], 'skipped' => []];
        }

        $due = PlanItem::ownedBy($user)
            ->whereIn('template_id', $autoTemplates)
            ->where('mode', PlanMode::Checklist->value)
            ->whereNull('skipped_at')
            ->whereNull('auto_posted_at')
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$from, $today])
            ->whereDoesntHave('transaction')
            ->orderBy('due_on')->orderBy('id')
            ->get();

        $posted = [];
        $skipped = [];
        foreach ($due as $item) {
            try {
                [, $created] = $this->check->handle($user, $item, new CheckPlanItemInput(
                    clientId: null,
                    occurredOn: $item->due_on?->toDateString(),
                    autoPost: true,
                ));
                if ($created) {
                    $posted[] = $item->getKey();
                }
            } catch (ConflictHttpException) {
                // Dicentang / di-skip di perangkat lain di antara query dan kunci: bukan urusan auto-post.
                continue;
            } catch (ValidationException $e) {
                // Mis. akun default kosong/diarsip: biarkan user mencentang manual.
                $skipped[$item->getKey()] = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped];
    }
}
