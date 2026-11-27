<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabungan dengan tujuan: penanda rekening tabungan, tujuan, dan log alokasi.
 * `savings_goals.allocated_amount` = Σ `savings_allocations.amount` (denormalisasi, diubah atomik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_savings')->default(false)->after('type');
            // Kolom sistem: kapan rekening tabungan masuk kondisi "terpakai"; tidak pernah dari request.
            $table->timestamp('savings_overdrawn_at')->nullable()->after('archived_at');
        });

        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->bigInteger('target_amount');
            $table->bigInteger('allocated_amount')->default(0);
            $table->date('target_date')->nullable();
            $table->timestamp('achieved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'uq_savings_goal_name');
        });
        DB::statement('ALTER TABLE savings_goals ADD CONSTRAINT chk_goal_target CHECK (target_amount > 0)');
        DB::statement('ALTER TABLE savings_goals ADD CONSTRAINT chk_goal_allocated CHECK (allocated_amount >= 0)');

        Schema::create('savings_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount'); // bertanda: positif = tambah, negatif = kurangi
            $table->date('occurred_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('move_group_id')->nullable(); // pasangan baris pindah alokasi
            $table->uuid('client_id')->nullable();     // idempotensi tulis, seperti transaksi
            $table->timestamps();

            $table->unique(['user_id', 'client_id'], 'uq_alloc_client');
            $table->unique('transaction_id', 'uq_alloc_transaction');
            $table->index(['savings_goal_id', 'occurred_on'], 'idx_alloc_goal_date');
        });
        DB::statement('ALTER TABLE savings_allocations ADD CONSTRAINT chk_alloc_nonzero CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_allocations');
        Schema::dropIfExists('savings_goals');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['is_savings', 'savings_overdrawn_at']);
        });
    }
};
