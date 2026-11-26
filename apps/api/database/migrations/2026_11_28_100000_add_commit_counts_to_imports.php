<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hasil commit import (#54): baris yang jadi transaksi dan yang dilewati user. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('committed_rows')->default(0)->after('invalid_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('committed_rows');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['committed_rows', 'skipped_rows']);
        });
    }
};
