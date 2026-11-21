<?php

use App\Enums\TransactionStatus;
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
            $table->foreignId('household_id')->constrained(); // denormalisasi: filter laporan tanpa join
            $table->foreignId('wallet_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained(); // NULL untuk transfer & adjustment
            $table->enum('type', TransactionType::values());
            $table->enum('status', TransactionStatus::values())->default(TransactionStatus::Posted->value);
            $table->bigInteger('amount');              // ber-tanda, sudut pandang wallet (Keputusan #2)
            $table->date('occurred_on');               // tanggal lokal household (Keputusan #7)
            $table->string('note')->nullable();
            $table->char('transfer_group_id', 36)->nullable();
            $table->char('client_id', 36)->nullable(); // idempotensi tulis web/mobile (Keputusan #11)
            $table->unsignedBigInteger('recurring_rule_id')->nullable();
            $table->char('import_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['household_id', 'occurred_on'], 'idx_tx_household_date');
            $table->index(['wallet_id', 'occurred_on'], 'idx_tx_wallet_date');
            $table->index(['category_id', 'occurred_on'], 'idx_tx_category_date');
            $table->index('transfer_group_id', 'idx_tx_transfer_group');
            $table->unique(['household_id', 'client_id'], 'uq_tx_client');  // NULL boleh berulang
            $table->unique(['wallet_id', 'import_hash'], 'uq_tx_import');    // NULL boleh berulang
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
