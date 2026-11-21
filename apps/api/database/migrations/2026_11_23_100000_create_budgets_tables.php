<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan #8: budget per kategori per bulan. Terpakai TIDAK disimpan (dihitung dari transaksi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained(); // tanpa cascade: kategori terpakai wajib diarsip
            $table->date('month'); // selalu tanggal 1
            $table->bigInteger('limit_amount');
            $table->timestamps();

            $table->unique(['household_id', 'category_id', 'month'], 'uq_budget');
            $table->index(['household_id', 'month'], 'idx_budget_household_month');
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budget_positive CHECK (limit_amount > 0)');
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budget_month CHECK (DAY(month) = 1)');

        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold'); // 80 | 100
            $table->bigInteger('spent_amount');       // snapshot saat alert
            $table->timestamp('triggered_at');

            $table->unique(['budget_id', 'threshold'], 'uq_budget_alert'); // tidak ada spam
        });

        DB::statement('ALTER TABLE budget_alerts ADD CONSTRAINT chk_budget_alert_threshold CHECK (threshold IN (80, 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('budgets');
    }
};
