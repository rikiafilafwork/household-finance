<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\DashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beranda web & mobile (Keputusan #11). `?month=YYYY-MM`, default bulan berjalan di zona waktu user.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardQuery $query): JsonResponse
    {
        $request->validate(['month' => ['sometimes', 'regex:/^'.PlanMonth::ROUTE_PATTERN.'$/']], [
            'month.regex' => 'Bulan harus berformat YYYY-MM.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $month = $request->filled('month')
            ? PlanMonth::parse($request->string('month')->toString())
            : PlanMonth::parse(CarbonImmutable::now($user->timezone)->format('Y-m'));

        return response()->json(['data' => $query->for($user, $month)]);
    }
}
