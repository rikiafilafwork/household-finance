<?php

use App\Enums\PlanItemSource;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana bulanan (Keputusan #7). Status item TIDAK disimpan: done = ada transaksi dengan
 * plan_item_id ini (belum dihapus), skipped = skipped_at terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');                                   // selalu tanggal 1 (zona waktu user)
            $table->enum('type', PlanItemType::values());
            $table->enum('mode', PlanMode::values());
            $table->string('name', 100);
            $table->foreignId('category_id')->constrained();         // tanpa cascade: kategori terpakai diarsip
            $table->bigInteger('planned_amount');
            $table->foreignId('account_id')->nullable()->constrained(); // akun default saat dicentang
            $table->date('due_on')->nullable();                      // checklist saja
            // FK ke plan_templates ditambahkan migration template (#43).
            $table->unsignedBigInteger('template_id')->nullable();
            $table->enum('source', PlanItemSource::values())->default(PlanItemSource::Manual->value);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();

            // Satu amplop per kategori per bulan; NULL untuk checklist (boleh berulang).
            $table->unsignedBigInteger('envelope_category_id')
                ->virtualAs("IF(mode = 'envelope', category_id, NULL)");

            $table->index(['user_id', 'month'], 'idx_plan_user_month');
            $table->unique(['template_id', 'month'], 'uq_plan_template_month');          // idempotensi plan:generate
            $table->unique(['user_id', 'month', 'envelope_category_id'], 'uq_plan_envelope');
        });

        DB::statement('ALTER TABLE plan_items ADD CONSTRAINT chk_plan_amount CHECK (planned_amount > 0)');
        DB::statement('ALTER TABLE plan_items ADD CONSTRAINT chk_plan_month CHECK (DAY(month) = 1)');
        DB::statement("ALTER TABLE plan_items ADD CONSTRAINT chk_plan_due CHECK (mode = 'checklist' OR due_on IS NULL)");

        // Transaksi terhubung ke item checklist (Keputusan #8). Tanpa cascade: item yang sudah
        // dicentang tidak bisa dihapus; batal centang & hapus transaksi menyetel plan_item_id = NULL.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('plan_item_id', 'transactions_plan_item_id_foreign')->references('id')->on('plan_items');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('transactions_plan_item_id_foreign');
        });
        Schema::dropIfExists('plan_items');
    }
};
