<?php

namespace App\Http\Controllers\Api;

use App\Actions\Plan\CheckPlanItem;
use App\Actions\Plan\CopyPlanFromPreviousMonth;
use App\Actions\Plan\DeletePlanItem;
use App\Actions\Plan\SavePlanItem;
use App\Actions\Plan\SkipPlanItem;
use App\Actions\Plan\UncheckPlanItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\CheckPlanItemRequest;
use App\Http\Requests\Plan\PlanItemRequest;
use App\Http\Resources\PlanComparisonResource;
use App\Http\Resources\PlanItemResource;
use App\Http\Resources\TransactionResource;
use App\Models\PlanItem;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparisonQuery;
use App\Queries\PlanItemComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rencana bulanan: `/plan/{YYYY-MM}` untuk daftar & tambah, `/plan-items/{id}` untuk item.
 * Angka rencana vs aktual dari PlanComparisonQuery (satu sumber untuk Rencana/Dashboard/Laporan).
 */
class PlanController extends Controller
{
    /**
     * Rencana sebulan + rencana vs aktual (PlanComparisonQuery). Kategori item dimuat untuk tampilan.
     */
    public function index(Request $request, string $month, PlanComparisonQuery $query): PlanComparisonResource
    {
        $plan = $query->for($this->user($request), PlanMonth::parse($month));
        $plan->items->each(fn (PlanItemComparison $row) => $row->item->loadMissing('category'));

        return new PlanComparisonResource($plan);
    }

    public function store(PlanItemRequest $request, string $month, SavePlanItem $save): JsonResponse
    {
        $item = $save->create($this->user($request), PlanMonth::parse($month), $request->toInput());

        return (new PlanItemResource($item->load(['category', 'transaction'])))->response()->setStatusCode(201);
    }

    public function copyFromPrevious(Request $request, string $month, CopyPlanFromPreviousMonth $copy): JsonResponse
    {
        $created = $copy->handle($this->user($request), PlanMonth::parse($month));

        return response()->json(['data' => ['created' => $created]], $created > 0 ? 201 : 200);
    }

    public function show(PlanItem $planItem): PlanItemResource
    {
        return new PlanItemResource($planItem->load(['category', 'transaction']));
    }

    public function update(PlanItemRequest $request, PlanItem $planItem, SavePlanItem $save): PlanItemResource
    {
        $item = $save->update($this->user($request), $planItem, $request->toInput($planItem));

        return new PlanItemResource($item->load(['category', 'transaction']));
    }

    public function destroy(Request $request, PlanItem $planItem, DeletePlanItem $delete): Response
    {
        $delete->handle($this->user($request), $planItem);

        return response()->noContent();
    }

    public function skip(Request $request, PlanItem $planItem, SkipPlanItem $skip): PlanItemResource
    {
        return new PlanItemResource($skip->skip($this->user($request), $planItem)->load(['category', 'transaction']));
    }

    public function unskip(Request $request, PlanItem $planItem, SkipPlanItem $skip): PlanItemResource
    {
        return new PlanItemResource($skip->unskip($this->user($request), $planItem)->load(['category', 'transaction']));
    }

    /**
     * Centang = transaksi baru (201). Retry client_id sama → 200 + transaksi yang sama.
     * Sudah dicentang (perangkat lain) → 409.
     */
    public function check(CheckPlanItemRequest $request, PlanItem $planItem, CheckPlanItem $check): JsonResponse
    {
        [$transaction, $created] = $check->handle($this->user($request), $planItem, $request->toInput());

        return response()->json([
            'data' => [
                'item' => new PlanItemResource($planItem->refresh()->load(['category', 'transaction'])),
                'transaction' => new TransactionResource($transaction->load('category')),
            ],
        ], $created ? 201 : 200);
    }

    public function uncheck(Request $request, PlanItem $planItem, UncheckPlanItem $uncheck): PlanItemResource
    {
        return new PlanItemResource($uncheck->handle($this->user($request), $planItem)->load(['category', 'transaction']));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
