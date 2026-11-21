<?php

use App\Enums\CategoryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', CategoryKind::values());
            $table->string('name', 60);
            $table->string('color', 9)->nullable();
            $table->string('icon', 40)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'kind', 'name'], 'uq_category_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
