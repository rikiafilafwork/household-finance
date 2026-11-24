<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapan plan:auto-post mencentang item ini (Keputusan #9). Auto-post hanya mencoba SEKALI per item:
 * kalau user membatalkan centangnya (mis. autodebet gagal), run berikutnya tidak mencentang lagi.
 * Batal centang memutus plan_item_id transaksi, jadi tanpa penanda ini item terlihat "belum".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_items', function (Blueprint $table) {
            $table->timestamp('auto_posted_at')->nullable()->after('skipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_items', function (Blueprint $table) {
            $table->dropColumn('auto_posted_at');
        });
    }
};
