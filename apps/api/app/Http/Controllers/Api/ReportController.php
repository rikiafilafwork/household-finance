<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\CashFlowQuery;
use App\Reports\CashVsNonCashReport;
use App\Reports\CategoryBreakdownReport;
use App\Reports\MonthlySummaryReport;
use App\Reports\TransactionCsvExporter;
use App\Reports\TrendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan (Minggu 7, Keputusan #14): dihitung langsung dari transactions & plan_items, tanpa
 * rollup/cache. Bulan (`YYYY-MM`) default = bulan berjalan di zona waktu user.
 */
class ReportController extends Controller
{
    private const MONTH = 'regex:/^'.PlanMonth::ROUTE_PATTERN.'$/';

    public function summary(Request $request, MonthlySummaryReport $report): JsonResponse
    {
        $request->validate(['month' => ['sometimes', self::MONTH]], $this->messages());

        return $this->data($report->for($this->user($request), $this->month($request, 'month')));
    }

    public function byCategory(Request $request, CategoryBreakdownReport $report): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', self::MONTH],
            'to' => ['sometimes', self::MONTH],
            'kind' => ['sometimes', Rule::enum(CategoryKind::class)],
        ], $this->messages());

        $to = $this->month($request, 'to');
        $from = $request->filled('from') ? PlanMonth::parse($request->string('from')->toString()) : $to;
        $this->assertMonthRange($from, $to, CategoryBreakdownReport::MAX_MONTHS);

        return $this->data($report->for(
            $this->user($request), $from, $to,
            CategoryKind::from($request->string('kind', CategoryKind::Expense->value)->toString()),
        ));
    }

    public function trend(Request $request, TrendReport $report): JsonResponse
    {
        $request->validate([
            'months' => ['sometimes', 'integer', 'min:1', 'max:'.TrendReport::MAX_MONTHS],
            'end' => ['sometimes', self::MONTH],
        ], $this->messages());

        return $this->data($report->for($this->user($request), $this->month($request, 'end'), $request->integer('months', 6)));
    }

    public function cashFlow(Request $request, CashFlowQuery $query): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'account' => ['sometimes', 'integer'],
        ]);
        $user = $this->user($request);
        $today = CarbonImmutable::now($user->timezone);
        $from = $request->string('from', $today->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', $today->toDateString())->toString();

        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= CashFlowQuery::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Rentang cash flow maksimal '.CashFlowQuery::MAX_DAYS.' hari.']);
        }

        $account = null;
        if ($request->filled('account')) {
            $account = Account::query()->find($request->integer('account'));
            abort_if($account === null, 404, 'Akun tidak ditemukan.');
        }

        return $this->data($query->for($user, $from, $to, $account));
    }

    public function cashVsNonCash(Request $request, CashVsNonCashReport $report): JsonResponse
    {
        $request->validate(['month' => ['sometimes', self::MONTH]], $this->messages());

        return $this->data($report->for($this->user($request), $this->month($request, 'month')));
    }

    /**
     * Unduh transaksi rentang tanggal sebagai CSV (streaming, memori datar). Default bulan berjalan.
     */
    public function export(Request $request, TransactionCsvExporter $exporter): StreamedResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'delimiter' => ['sometimes', Rule::in(['semicolon', 'comma', 'tab'])],
        ]);
        $user = $this->user($request);
        $today = CarbonImmutable::now($user->timezone);
        $from = $request->string('from', $today->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', $today->toDateString())->toString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        $delimiter = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t"][$request->string('delimiter', 'semicolon')->toString()];

        return response()->streamDownload(function () use ($exporter, $user, $from, $to, $delimiter) {
            foreach ($exporter->lines($user, $from, $to, $delimiter) as $line) {
                echo $line;
            }
        }, TransactionCsvExporter::filename($from, $to), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function month(Request $request, string $field): PlanMonth
    {
        return $request->filled($field)
            ? PlanMonth::parse($request->string($field)->toString())
            : PlanMonth::parse(CarbonImmutable::now($this->user($request)->timezone)->format('Y-m'));
    }

    private function assertMonthRange(PlanMonth $from, PlanMonth $to, int $max): void
    {
        if ($to->key() < $from->key()) {
            throw ValidationException::withMessages(['to' => 'Bulan akhir tidak boleh sebelum bulan awal.']);
        }
        if (count(PlanMonth::range($from, $to)) > $max) {
            throw ValidationException::withMessages(['from' => "Rentang maksimal {$max} bulan."]);
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        $message = 'Bulan harus berformat YYYY-MM.';

        return ['month.regex' => $message, 'from.regex' => $message, 'to.regex' => $message, 'end.regex' => $message];
    }

    /** @param  array<mixed>  $data */
    private function data(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
