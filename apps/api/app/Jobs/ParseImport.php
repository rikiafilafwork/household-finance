<?php

namespace App\Jobs;

use App\Actions\Imports\StageImportRows;
use App\Models\Import;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Keputusan #13: parse file mutasi yang baru diunggah → `import_rows`. Parsing deterministik, jadi
 * tidak di-retry; kegagalan tak terduga membuat import `failed` (user bisa mengunggah ulang).
 */
class ParseImport implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly int $importId,
    ) {}

    public function handle(StageImportRows $stage): void
    {
        $import = $this->import();
        if ($import !== null) {
            $stage->handle($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        $import = $this->import();
        if ($import !== null) {
            app(StageImportRows::class)->fail($import, 'Gagal memproses file. Coba unggah ulang.');
        }
    }

    private function import(): ?Import
    {
        $user = User::find($this->userId);

        return $user === null ? null : Import::ownedBy($user)->find($this->importId);
    }
}
