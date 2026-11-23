<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alert amplop (Keputusan #10). UNIQUE (plan_item_id, threshold) = tepat sekali per ambang:
 * konflik berarti sudah pernah alert, tidak ada spam (juga kalau dua job berjalan bersamaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_item_id')->constrained()->cascadeOnDelete(); // hanya amplop
            $table->unsignedTinyInteger('threshold');                            // 80 | 100
            $table->bigInteger('spent_amount');                                  // snapshot saat alert
            $table->timestamp('triggered_at');

            $table->unique(['plan_item_id', 'threshold'], 'uq_budget_alert');
        });

        DB::statement('ALTER TABLE budget_alerts ADD CONSTRAINT chk_budget_alert_threshold CHECK (threshold IN (80, 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
    }
};
