<?php

use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained(); // NULL untuk transfer & adjustment
            $table->enum('type', TransactionType::values());
            $table->bigInteger('amount');              // ber-tanda, sudut pandang akun (Keputusan #2)
            $table->date('occurred_on');               // tanggal lokal user (Keputusan #12)
            $table->string('note')->nullable();
            $table->char('transfer_group_id', 36)->nullable();
            $table->char('client_id', 36)->nullable(); // idempotensi tulis web/mobile (Keputusan #15)
            // Terhubung ke item checklist (Keputusan #8). FK ke plan_items ditambahkan migration plan (#125).
            $table->unsignedBigInteger('plan_item_id')->nullable();
            $table->char('import_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'occurred_on'], 'idx_tx_user_date');
            $table->index(['user_id', 'category_id', 'occurred_on'], 'idx_tx_user_cat_date');
            $table->index(['account_id', 'occurred_on'], 'idx_tx_account_date');
            $table->index('transfer_group_id', 'idx_tx_transfer_group');
            $table->unique(['user_id', 'client_id'], 'uq_tx_client');      // NULL boleh berulang
            $table->unique('plan_item_id', 'uq_tx_plan_item');             // 1 item checklist = maks 1 transaksi
            $table->unique(['account_id', 'import_hash'], 'uq_tx_import'); // NULL boleh berulang
        });

        // MySQL >= 8.0.16 menegakkan CHECK constraint.
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_tx_sign CHECK (
               (type = 'income'     AND amount > 0)
            OR (type = 'expense'    AND amount < 0)
            OR (type = 'transfer'   AND amount <> 0 AND transfer_group_id IS NOT NULL)
            OR (type = 'adjustment' AND amount <> 0)
        )");

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_tx_category CHECK (
            type IN ('income', 'expense') OR category_id IS NULL
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
