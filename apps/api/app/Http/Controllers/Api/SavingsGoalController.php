<?php

namespace App\Http\Controllers\Api;

use App\Actions\Savings\ArchiveSavingsGoal;
use App\Actions\Savings\SaveSavingsGoal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\StoreSavingsGoalRequest;
use App\Http\Requests\Savings\UpdateSavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
}
