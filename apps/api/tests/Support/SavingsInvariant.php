<?php

use Illuminate\Support\Facades\DB;

/**
 * Invariant tabungan untuk SEMUA tujuan: allocated_amount = Σ log alokasi, dan tidak pernah negatif.
 * (Global function, di-require dari tests/Pest.php supaya dipakai Feature maupun Concurrency.)
 */
function assertSavingsBalanced(): void
{
    $mismatches = DB::select('
        SELECT g.id, g.allocated_amount, COALESCE(SUM(a.amount), 0) AS expected
          FROM savings_goals g
          LEFT JOIN savings_allocations a ON a.savings_goal_id = g.id
         GROUP BY g.id, g.allocated_amount
        HAVING g.allocated_amount <> expected OR g.allocated_amount < 0
    ');

    expect($mismatches)->toBe([], 'Alokasi tujuan meleset dari log: '.json_encode($mismatches));
}
