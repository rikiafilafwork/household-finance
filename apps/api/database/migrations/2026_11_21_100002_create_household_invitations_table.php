<?php

use App\Enums\HouseholdRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->enum('role', HouseholdRole::values())->default(HouseholdRole::Member->value);
            $table->char('token_hash', 64);            // sha256(token); token asli hanya ada di email
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('token_hash', 'uq_invitation_token');
            $table->index(['household_id', 'email'], 'idx_invitation_household_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_invitations');
    }
};
