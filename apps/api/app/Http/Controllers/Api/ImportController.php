<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Enums\ImportRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Http\Resources\ImportRowResource;
use App\Jobs\ParseImport;
use App\Models\Account;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Import CSV mutasi rekening (Keputusan #13), hanya untuk akun `bank`. Upload membalas 202; web
 * mem-polling GET /imports/{id} sampai status `ready` (atau `failed`), lalu membaca baris preview.
 */
class ImportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(Import::query()->latest('id')->paginate(20));
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = Account::query()->find($request->integer('account_id'));
        abort_if($account === null, 404, 'Akun tidak ditemukan.');

        if ($account->type !== AccountType::Bank) {
            throw ValidationException::withMessages(['account_id' => 'Import mutasi hanya untuk akun rekening (bank).']);
        }
        if ($account->isArchived()) {
            throw ValidationException::withMessages(['account_id' => 'Akun ini sudah diarsip.']);
        }

        $format = $request->statementFormat();
        $mapping = $request->columnMapping()?->toArray();
        if ($format->needsColumnMapping() && $mapping === null) {
            $mapping = $this->preset($account, $format->value)
                ?? throw ValidationException::withMessages(['column_mapping' => 'Petakan kolom file ini (belum ada pemetaan tersimpan untuk akun ini).']);
        }

        $file = $request->file('file');
        abort_if(! $file instanceof UploadedFile, 422);

        $import = Import::create([
            'account_id' => $account->id,
            'bank_format' => $format,
            'column_mapping' => $format->needsColumnMapping() ? $mapping : null,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_path' => $file->store('imports/'.$user->id, Import::DISK),
        ]);

        ParseImport::dispatch($user->id, $import->id);

        return (new ImportResource($import->refresh()))->response()->setStatusCode(202);
    }

    public function show(Import $import): ImportResource
    {
        return new ImportResource($import);
    }

    public function rows(Request $request, Import $import): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(ImportRowStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $rows = $import->rows()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('row_number')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 100));

        return ImportRowResource::collection($rows);
    }

    /**
     * Pemetaan kolom import terakhir untuk akun + format yang sama.
     *
     * @return array<string, mixed>|null
     */
    private function preset(Account $account, string $format): ?array
    {
        /** @var array<string, mixed>|null */
        return Import::query()
            ->where('account_id', $account->id)
            ->where('bank_format', $format)
            ->whereNotNull('column_mapping')
            ->latest('id')
            ->value('column_mapping');
    }
}
