<?php

use App\Enums\WalletType;
use App\Enums\WalletVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users'); // wajib kalau private
            $table->enum('visibility', WalletVisibility::values())->default(WalletVisibility::Shared->value);
            $table->enum('type', WalletType::values());
            $table->string('name', 100);
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('balance')->default(0); // denormalisasi, dijaga wallets:reconcile
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'visibility'], 'idx_wallets_household');
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT chk_private_has_owner
            CHECK (visibility = 'shared' OR owner_user_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
