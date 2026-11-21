<?php

use App\Enums\HouseholdRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('currency', 3)->default('IDR');
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', HouseholdRole::values())->default(HouseholdRole::Member->value);
            $table->timestamp('joined_at');

            $table->primary(['household_id', 'user_id']);
            $table->index('user_id', 'idx_members_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');
    }
};
