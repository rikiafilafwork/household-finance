<?php

namespace App\Http\Controllers\Api;

use App\Actions\Savings\AllocateSavings;
use App\Actions\Savings\ArchiveSavingsGoal;
use App\Actions\Savings\MoveSavingsAllocation;
use App\Actions\Savings\SaveSavingsGoal;
use App\Actions\Savings\UseSavings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\AllocateRequest;
use App\Http\Requests\Savings\MoveAllocationRequest;
use App\Http\Requests\Savings\StoreSavingsGoalRequest;
use App\Http\Requests\Savings\UpdateSavingsGoalRequest;
use App\Http\Requests\Savings\UseSavingsRequest;
use App\Http\Resources\SavingsAllocationResource;
use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\TransactionResource;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Tujuan tabungan milik user login; `{savingsGoal}` memakai global scope BelongsToUser (milik orang lain = 404).
 */
class SavingsGoalController extends Controller
{
    public function store(StoreSavingsGoalRequest $request, SaveSavingsGoal $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{account_id: int, name: string, target_amount: int, target_date?: ?string, sort_order?: int} $data */
        $data = $request->validated();

        return (new SavingsGoalResource($save->create($user, $data)))->response()->setStatusCode(201);
    }

    public function show(SavingsGoal $savingsGoal): SavingsGoalResource
    {
        return new SavingsGoalResource($savingsGoal);
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal, SaveSavingsGoal $save): SavingsGoalResource
    {
        /** @var array{name?: string, target_amount?: int, target_date?: ?string, sort_order?: int} $data */
        $data = $request->validated();

        return new SavingsGoalResource($save->update($savingsGoal, $data));
    }

    public function destroy(SavingsGoal $savingsGoal, ArchiveSavingsGoal $actions): Response
    {
        $actions->delete($savingsGoal);

        return response()->noContent();
    }

    public function archive(SavingsGoal $savingsGoal, ArchiveSavingsGoal $actions): SavingsGoalResource
    {
        return new SavingsGoalResource($actions->archive($savingsGoal));
    }

    public function unarchive(SavingsGoal $savingsGoal, ArchiveSavingsGoal $actions): SavingsGoalResource
    {
        return new SavingsGoalResource($actions->unarchive($savingsGoal));
    }

    public function achieve(SavingsGoal $savingsGoal, ArchiveSavingsGoal $actions): SavingsGoalResource
    {
        return new SavingsGoalResource($actions->markAchieved($savingsGoal));
    }

    public function unachieve(SavingsGoal $savingsGoal, ArchiveSavingsGoal $actions): SavingsGoalResource
    {
        return new SavingsGoalResource($actions->unmarkAchieved($savingsGoal));
    }

    /**
     * Riwayat alokasi tujuan, terbaru dulu, cursor pagination (occurred_on DESC, id DESC) seperti transaksi.
     */
    public function allocations(Request $request, SavingsGoal $savingsGoal): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $page = $savingsGoal->allocations()
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->withQueryString();

        return SavingsAllocationResource::collection($page);
    }

    public function allocate(AllocateRequest $request, SavingsGoal $savingsGoal, AllocateSavings $allocate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$allocation, $created] = $allocate->handle(
            $user, $savingsGoal, $request->integer('amount'), $request->string('occurred_on')->toString(),
            $request->note(), $request->clientId(),
        );

        // Retry dengan client_id yang sama → 200 + baris lama.
        return (new SavingsAllocationResource($allocation))->response()->setStatusCode($created ? 201 : 200);
    }

    public function move(MoveAllocationRequest $request, SavingsGoal $savingsGoal, MoveSavingsAllocation $move): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = SavingsGoal::ownedBy($user)->findOrFail($request->integer('to_goal_id'));

        [$allocation, $created] = $move->handle($user, $savingsGoal, $to, $request->integer('amount'), $request->note(), $request->clientId());

        return (new SavingsAllocationResource($allocation))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * "Gunakan": transaksi pengeluaran dari rekening tabungan + alokasi tujuan berkurang sebesar itu.
     */
    public function use(UseSavingsRequest $request, SavingsGoal $savingsGoal, UseSavings $use): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$transaction, $created] = $use->handle(
            $user, $savingsGoal, $request->integer('amount'), $request->integer('category_id'),
            $request->string('occurred_on')->toString(), $request->note(), $request->clientId(),
        );

        return response()->json(['data' => [
            'transaction' => new TransactionResource($transaction->load(['category', 'savingsAllocation'])),
            'goal' => new SavingsGoalResource($savingsGoal->refresh()),
        ]], $created ? 201 : 200);
    }
}
