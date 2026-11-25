<?php

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import CSV mutasi rekening (Keputusan #13). Upload → `imports` + file di disk privat → job
 * ParseImport → baris staging di `import_rows` → preview → commit (#54) jadi transaksi.
 * Akun harus bertipe bank; divalidasi di aplikasi (MySQL CHECK tidak bisa melihat tabel lain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->string('bank_format', 30);                       // StatementFormat
            $table->json('column_mapping')->nullable();             // untuk generic; dipakai ulang sebagai preset
            $table->json('headers')->nullable();                    // nama kolom file (urutan asli) untuk preview
            $table->string('original_filename', 255);
            $table->string('file_path', 255)->nullable();           // disk privat, dihapus setelah commit
            $table->enum('status', ImportStatus::values())->default(ImportStatus::Pending->value);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('possible_duplicate_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_imports_user');
            $table->index(['account_id', 'bank_format', 'created_at'], 'idx_imports_preset');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');                  // nomor baris di file (seperti di Excel)
            $table->date('occurred_on')->nullable();
            $table->bigInteger('amount')->nullable();               // sudah ber-tanda, sudut pandang akun
            $table->string('description', 255)->nullable();
            $table->char('fingerprint', 64)->nullable();            // FingerprintCalculator
            $table->enum('status', ImportRowStatus::values());
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->string('error', 255)->nullable();
            $table->json('raw');

            $table->index(['import_id', 'row_number'], 'idx_import_rows');
        });

        // Baris yang terbaca wajib lengkap; baris invalid wajib punya alasan.
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT chk_import_row_valid CHECK (
            (status = 'invalid' AND error IS NOT NULL)
            OR (status <> 'invalid' AND occurred_on IS NOT NULL AND amount IS NOT NULL AND amount <> 0 AND fingerprint IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
