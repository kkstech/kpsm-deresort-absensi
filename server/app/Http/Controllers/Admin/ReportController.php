<?php

namespace App\Http\Controllers\Admin;

use App\Actions\FinalizeReportPeriod;
use App\Actions\ReopenReportPeriod;
use App\Exports\AttendanceReportSpout;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\SiteSetting;
use App\Services\ReportService;
use App\Support\WorkTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function filters(Request $request, ReportService $reportService): JsonResponse
    {
        $employees = $reportService->visibleActiveEmployees($request->user())
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id']);

        $departments = Department::query()
            ->whereIn('id', $employees->pluck('department_id')->filter()->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'employees' => $employees,
            'departments' => $departments,
        ]);
    }

    public function summary(Request $request, ReportService $reportService): JsonResponse
    {
        $filters = $reportService->validatedFilters($request);
        $summary = $reportService->getSummary($request->user(), $filters);

        return response()->json($summary);
    }

    public function details(Request $request, ReportService $reportService): JsonResponse
    {
        $filters = $reportService->validatedFilters($request);
        $perPage = $filters['per_page'] ?? 50;
        $paginator = $reportService->getDetails($request->user(), $filters, $perPage);

        return response()->json([
            'filters' => $filters,
            'period_status' => $reportService->determinePeriodStatus($filters['from'], $filters['to']),
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function shiftBreakdown(Request $request, ReportService $reportService): JsonResponse
    {
        $filters = $reportService->validatedFilters($request);

        return response()->json([
            'filters' => $filters,
            'period_status' => $reportService->determinePeriodStatus($filters['from'], $filters['to']),
            'data' => $reportService->getShiftBreakdown($request->user(), $filters),
        ]);
    }

    public function overtime(Request $request, ReportService $reportService): JsonResponse
    {
        $filters = $reportService->validatedFilters($request);
        $orders = $reportService->getOvertime($request->user(), $filters);

        return response()->json([
            'period_status' => $reportService->determinePeriodStatus($filters['from'], $filters['to']),
            'data' => $orders,
        ]);
    }

    public function finalize(Request $request, FinalizeReportPeriod $finalizer, ReportService $reportService): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Hanya admin yang dapat memfinalkan laporan.');

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($finalizer->execute($user, $data['from'], $data['to'], $reportService));
    }

    public function reopen(Request $request, ReopenReportPeriod $reopener): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Hanya admin yang dapat membuka ulang laporan.');

        $reason = $request->input('reason');
        if (is_string($reason)) {
            $request->merge(['reason' => trim($reason)]);
        }
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($reopener->execute($user, $data['from'], $data['to'], $data['reason']));
    }

    public function exportExcel(Request $request, ReportService $reportService, AttendanceReportSpout $spout): BinaryFileResponse
    {
        $filters = $reportService->validatedFilters($request);
        $summary = $reportService->getSummary($request->user(), $filters);
        $details = $reportService->getAllDetailsForExport($request->user(), $filters);
        $shiftBreakdown = $reportService->getShiftBreakdown($request->user(), $filters);

        $tempPath = tempnam(sys_get_temp_dir(), 'laporan_absensi_').'.xlsx';
        $spout->export($summary, $details, $shiftBreakdown, $tempPath);

        $filename = "laporan_kehadiran_{$filters['from']}_{$filters['to']}.xlsx";

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function exportPdf(Request $request, ReportService $reportService): Response
    {
        $filters = $reportService->validatedFilters($request);
        $type = $request->input('type', 'summary');

        if (! empty($filters['department_id'])) {
            $dept = Department::query()->find($filters['department_id']);
            $filters['department_name'] = $dept?->name;
        }

        if ($type === 'shift-breakdown') {
            $rows = $reportService->getShiftBreakdown($request->user(), $filters);
            $pdf = Pdf::loadView('reports.shift-breakdown-pdf', [
                'site' => SiteSetting::current(),
                'rows' => $rows,
                'filters' => $filters,
                'periodStatus' => $reportService->determinePeriodStatus($filters['from'], $filters['to']),
                'generatedAtLocal' => WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
            ])->setPaper('a4', 'landscape');

            $filename = "frekuensi_shift_{$filters['from']}_{$filters['to']}.pdf";
        } elseif ($type === 'detail') {
            $details = $reportService->getAllDetailsForExport($request->user(), $filters);
            $periodStatus = $reportService->determinePeriodStatus($filters['from'], $filters['to']);

            $pdf = Pdf::loadView('reports.attendance-detail-pdf', [
                'site' => SiteSetting::current(),
                'details' => $details,
                'filters' => $filters,
                'periodStatus' => $periodStatus,
                'generatedAtLocal' => WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
            ])->setPaper('a4', 'landscape');

            $filename = "detail_kehadiran_{$filters['from']}_{$filters['to']}.pdf";
        } else {
            $summary = $reportService->getSummary($request->user(), $filters);
            $summary['filters'] = $filters;

            $pdf = Pdf::loadView('reports.attendance-summary-pdf', [
                'site' => SiteSetting::current(),
                'summary' => $summary,
            ])->setPaper('a4', 'landscape');

            $filename = "rekap_kehadiran_{$filters['from']}_{$filters['to']}.pdf";
        }

        return $pdf->download($filename);
    }
}
