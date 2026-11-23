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
use App\Http\Resources\PlanItemResource;
use App\Http\Resources\TransactionResource;
use App\Models\PlanItem;
use App\Models\User;
use App\Plan\PlanMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Rencana bulanan: `/plan/{YYYY-MM}` untuk daftar & tambah, `/plan-items/{id}` untuk item.
 * Angka rencana vs aktual ada di PlanComparisonQuery (#127).
 */
class PlanController extends Controller
{
    public function index(string $month): AnonymousResourceCollection
    {
        $items = PlanItem::query()
            ->forMonth(PlanMonth::parse($month)->firstDay())
            ->with(['category', 'transaction'])
            ->orderBy('sort_order')
            ->orderByRaw('due_on IS NULL, due_on')
            ->orderBy('id')
            ->get();

        return PlanItemResource::collection($items);
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
