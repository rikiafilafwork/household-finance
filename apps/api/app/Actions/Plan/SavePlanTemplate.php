<?php

namespace App\Actions\Plan;

use App\Enums\Frequency;
use App\Enums\PlanMode;
use App\Models\PlanTemplate;
use App\Models\User;
use App\Plan\PlanReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah / ubah template (Keputusan #9). Mengubah template TIDAK menyentuh plan_items yang sudah
 * dibuat darinya; perubahan baru berlaku saat plan:generate berikutnya.
 *
 * Aturan (juga dijaga CHECK di DB):
 *  - yearly wajib month_of_year; monthly tanpa month_of_year;
 *  - amplop tanpa day_of_month dan tanpa auto_post;
 *  - auto_post hanya checklist, dan butuh day_of_month (kapan) + account_id (dari akun mana);
 *  - template amplop aktif per kategori tidak boleh bertabrakan di bulan yang sama (plan_items
 *    hanya boleh satu amplop per kategori per bulan).
 */
class SavePlanTemplate
{
    public function create(User $user, PlanTemplateInput $input): PlanTemplate
    {
        return DB::transaction(function () use ($user, $input) {
            $this->lockUser($user);
            $this->assertValid($user, $input);

            return PlanTemplate::create(['user_id' => $user->getKey(), ...$this->attributes($input)]);
        });
    }

    public function update(User $user, PlanTemplate $template, PlanTemplateInput $input): PlanTemplate
    {
        return DB::transaction(function () use ($user, $template, $input) {
            $this->lockUser($user);
            /** @var PlanTemplate $locked */
            $locked = PlanTemplate::ownedBy($user)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $this->assertValid($user, $input, $locked);

            $locked->fill($this->attributes($input))->save();

            return $locked;
        });
    }

    /**
     * Serialisasi tulis template per user, supaya cek tabrakan amplop tidak bisa dilewati dua
     * request bersamaan.
     */
    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PlanTemplateInput $input): array
    {
        return [
            'type' => $input->type,
            'mode' => $input->mode,
            'name' => $input->name,
            'category_id' => $input->categoryId,
            'planned_amount' => $input->plannedAmount,
            'account_id' => $input->accountId,
            'frequency' => $input->frequency,
            'day_of_month' => $input->dayOfMonth,
            'month_of_year' => $input->monthOfYear,
            'auto_post' => $input->autoPost,
            'is_active' => $input->isActive,
        ];
    }

    private function assertValid(User $user, PlanTemplateInput $input, ?PlanTemplate $current = null): void
    {
        $errors = [];
        if ($input->frequency === Frequency::Yearly && $input->monthOfYear === null) {
            $errors['month_of_year'] = 'Template tahunan wajib punya bulan.';
        }
        if ($input->frequency === Frequency::Monthly && $input->monthOfYear !== null) {
            $errors['month_of_year'] = 'Bulan hanya untuk template tahunan.';
        }
        if ($input->mode === PlanMode::Envelope && $input->dayOfMonth !== null) {
            $errors['day_of_month'] = 'Amplop tidak punya tanggal jatuh tempo.';
        }
        if ($input->autoPost) {
            if ($input->mode !== PlanMode::Checklist) {
                $errors['auto_post'] = 'Auto-post hanya untuk item checklist.';
            } elseif ($input->dayOfMonth === null) {
                $errors['day_of_month'] = 'Auto-post butuh tanggal jatuh tempo.';
            } elseif ($input->accountId === null) {
                $errors['account_id'] = 'Auto-post butuh rekening default.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        PlanReferences::assert($user, $input->type, $input->categoryId, $input->accountId, $current?->category_id, $current?->account_id);

        if ($input->mode === PlanMode::Envelope && $input->isActive && $this->envelopeClashes($user, $input, $current)) {
            throw ValidationException::withMessages([
                'category_id' => 'Sudah ada template amplop aktif untuk kategori ini di bulan yang sama.',
            ]);
        }
    }

    /**
     * Dua template amplop aktif berkategori sama bertabrakan kalau ada bulan yang keduanya hasilkan:
     * salah satunya bulanan, atau keduanya tahunan di bulan yang sama.
     */
    private function envelopeClashes(User $user, PlanTemplateInput $input, ?PlanTemplate $current): bool
    {
        return PlanTemplate::ownedBy($user)
            ->where('mode', PlanMode::Envelope->value)
            ->where('category_id', $input->categoryId)
            ->where('is_active', true)
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current?->getKey()))
            ->get()
            ->contains(fn (PlanTemplate $other) => $input->frequency === Frequency::Monthly
                || $other->frequency === Frequency::Monthly
                || $other->month_of_year === $input->monthOfYear);
    }
}
