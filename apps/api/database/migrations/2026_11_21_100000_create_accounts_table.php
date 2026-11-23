<?php

use App\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun = tempat uang milik satu user (Keputusan #5, #6): rekening, e-wallet, dompet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', AccountType::values());
            $table->string('name', 100);
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('balance')->default(0); // denormalisasi, dijaga accounts:reconcile (Keputusan #3)
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'uq_account_name');
            $table->index(['user_id', 'type'], 'idx_accounts_user_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
