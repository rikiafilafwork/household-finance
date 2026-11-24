<?php

use App\Enums\Frequency;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template bulanan (Keputusan #9): item yang berulang tiap bulan atau tiap tahun. `plan:generate`
 * membuat plan_items darinya; idempotensi lewat UNIQUE (template_id, month) di plan_items.
 * Mengubah/menghapus template tidak menyentuh item yang sudah dibuat (FK ON DELETE SET NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', PlanItemType::values());
            $table->enum('mode', PlanMode::values());
            $table->string('name', 100);
            $table->foreignId('category_id')->constrained();            // tanpa cascade: kategori terpakai diarsip
            $table->bigInteger('planned_amount');
            $table->foreignId('account_id')->nullable()->constrained(); // akun default (wajib kalau auto_post)
            // Budget itu bulanan: hanya monthly & yearly (Frequency::Weekly tidak dipakai di sini).
            $table->enum('frequency', [Frequency::Monthly->value, Frequency::Yearly->value])->default(Frequency::Monthly->value);
            $table->unsignedTinyInteger('day_of_month')->nullable();   // 1..31, dibatasi hari terakhir bulan; NULL untuk amplop
            $table->unsignedTinyInteger('month_of_year')->nullable();  // 1..12, hanya yearly
            $table->boolean('auto_post')->default(false);              // hanya checklist
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'idx_templates_user_active');
        });

        foreach ([
            'chk_tpl_amount' => 'planned_amount > 0',
            'chk_tpl_day' => 'day_of_month IS NULL OR day_of_month BETWEEN 1 AND 31',
            'chk_tpl_day_mode' => "mode = 'checklist' OR day_of_month IS NULL",
            // IS NOT NULL eksplisit: CHECK yang bernilai NULL dianggap LULUS oleh MySQL, jadi
            // `month_of_year BETWEEN 1 AND 12` saja meloloskan template tahunan tanpa bulan.
            'chk_tpl_month' => "(frequency = 'yearly' AND month_of_year IS NOT NULL AND month_of_year BETWEEN 1 AND 12) OR (frequency = 'monthly' AND month_of_year IS NULL)",
            'chk_tpl_auto' => "auto_post = FALSE OR (mode = 'checklist' AND day_of_month IS NOT NULL AND account_id IS NOT NULL)",
        ] as $name => $check) {
            DB::statement("ALTER TABLE plan_templates ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        Schema::table('plan_items', function (Blueprint $table) {
            $table->foreign('template_id', 'plan_items_template_id_foreign')->references('id')->on('plan_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plan_items', function (Blueprint $table) {
            $table->dropForeign('plan_items_template_id_foreign');
        });
        Schema::dropIfExists('plan_templates');
    }
};
