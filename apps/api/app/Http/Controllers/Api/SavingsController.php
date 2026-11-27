<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\SavingsGoalResource;
use App\Models\User;
use App\Queries\SavingsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ringkasan tabungan per rekening tabungan (saldo, dialokasikan, belum dialokasikan, terpakai, tujuan).
 */
class SavingsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $summary = SavingsQuery::for($user);

        return response()->json(['data' => [
            'accounts' => array_map(fn (array $row) => [
                'account' => new AccountResource($row['account']),
                'balance' => $row['balance'],
                'allocated' => $row['allocated'],
                'unallocated' => $row['unallocated'],
                'overdrawn' => $row['overdrawn'],
                'goals' => SavingsGoalResource::collection($row['goals']),
            ], $summary['accounts']),
            'totals' => $summary['totals'],
        ]]);
    }
}
