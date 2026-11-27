<?php

namespace App\Actions\Savings;

use App\Models\Account;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveSavingsGoal
{
    /** Tujuan arsip tidak boleh memegang uang, supaya uang tidak bisa disisihkan dua kali. */
    public function archive(SavingsGoal $goal): SavingsGoal
    {
        return $this->locked($goal, function (SavingsGoal $locked) {
            $this->ensureEmpty($locked, 'Tujuan ini masih memegang uang. Pindahkan atau kurangi alokasinya sampai Rp0 dulu.');

            $locked->update(['archived_at' => $locked->archived_at ?? now()]);

            return $locked;
        });
    }

    public function unarchive(SavingsGoal $goal): SavingsGoal
    {
        $goal->update(['archived_at' => null]);

        return $goal;
    }

    /**
     * Hapus permanen hanya kalau kosong dan tidak pernah punya riwayat alokasi; selain itu wajib arsip.
     */
    public function delete(SavingsGoal $goal): void
    {
        $this->locked($goal, function (SavingsGoal $locked) {
            $this->ensureEmpty($locked, 'Tujuan ini masih memegang uang. Pindahkan atau kurangi alokasinya sampai Rp0 dulu, lalu arsipkan.');

            if (SavingsAllocation::ownedBy($locked->user_id)->where('savings_goal_id', $locked->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'goal' => 'Tujuan ini sudah punya riwayat alokasi. Arsipkan saja supaya riwayatnya tetap utuh.',
                ]);
            }

            $locked->delete();
        });
    }

    public function markAchieved(SavingsGoal $goal): SavingsGoal
    {
        $goal->update(['achieved_at' => $goal->achieved_at ?? now()]);

        return $goal;
    }

    public function unmarkAchieved(SavingsGoal $goal): SavingsGoal
    {
        $goal->update(['achieved_at' => null]);

        return $goal;
    }

    /**
     * Urutan kunci: rekening → tujuan, sama dengan Action alokasi.
     *
     * @template T
     *
     * @param  \Closure(SavingsGoal): T  $callback
     * @return T
     */
    private function locked(SavingsGoal $goal, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($goal, $callback) {
            Account::ownedBy($goal->user_id)->whereKey($goal->account_id)->lockForUpdate()->first();

            /** @var SavingsGoal $locked */
            $locked = SavingsGoal::ownedBy($goal->user_id)->whereKey($goal->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }

    private function ensureEmpty(SavingsGoal $goal, string $message): void
    {
        if ($goal->allocated_amount > 0) {
            throw ValidationException::withMessages(['goal' => $message]);
        }
    }
}
