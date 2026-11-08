<?php

namespace App\Exports;

use App\Models\SiteSetting;
use App\Support\WorkTime;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class AttendanceReportSpout
{
    public function export(array $summary, iterable $details, iterable $shiftBreakdown, iterable $overtime, string $filePath): void
    {
        $site = SiteSetting::current();
        $siteName = $site->site_name ?? 'Sistem Absensi Resort';
        $filters = $summary['filters'];
        $periodStatus = $summary['period_status'];
        $kpi = $summary['kpi'];
        $rows = $summary['rows'];

        $writer = new Writer;
        $writer->openToFile($filePath);

        $titleStyle = (new Style)
            ->withFontBold(true)
            ->withFontSize(14)
            ->withFontColor('0F172A');

        $subTitleStyle = (new Style)
            ->withFontItalic(true)
            ->withFontSize(10)
            ->withFontColor('475569');

        $headerStyle = (new Style)
            ->withFontBold(true)
            ->withFontSize(10)
            ->withFontColor('0F172A')
            ->withBackgroundColor('E2E8F0');

        $totalRowStyle = (new Style)
            ->withFontBold(true)
            ->withFontSize(10)
            ->withFontColor('0F172A')
            ->withBackgroundColor('F1F5F9');

        $badgeStyle = (new Style)
            ->withFontBold(true)
            ->withFontSize(11)
            ->withFontColor($periodStatus === 'FINALIZED' ? '15803D' : 'B45309');

        // ======================= SHEET 1: RINGKASAN =======================
        $sheet1 = $writer->getCurrentSheet();
        $sheet1->setName('Ringkasan Kehadiran');

        // Title Block
        $writer->addRow(Row::fromValuesWithStyle([$siteName], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(['LAPORAN REKAPITULASI KEHADIRAN KARYAWAN'], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle([
            "Periode: {$filters['from']} s.d. {$filters['to']} | Status Rekap: {$periodStatus} | Tanggal Cetak: ".WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
        ], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));

        // Table Headers
        $summaryHeaders = [
            'No',
            'NIK',
            'Nama Karyawan',
            'Departemen',
            'Jabatan',
            'Level',
            'Hari Jadwal',
            'Hari Hadir',
            'Sesi Jadwal',
            'Sesi Hadir',
            'Menit Valid',
            'Durasi Valid',
            'Kali Terlambat',
            'Menit Terlambat',
            'Terlambat Berizin',
            'Terlambat Tanpa Izin',
            'Sesi Review',
            'Lembur Aktual (Menit)',
            'Durasi Lembur Aktual',
            'Lembur Diakui (Menit)',
            'Durasi Lembur Diakui',
            'Hari Cuti',
            'Uang Makan (Rp)',
            'Uang Transport (Rp)',
            'Total Tunjangan (Rp)',
        ];
        $writer->addRow(Row::fromValuesWithStyle($summaryHeaders, $headerStyle));

        // Data Rows
        $no = 1;
        foreach ($rows as $item) {
            $emp = $item['employee'];
            $writer->addRow(Row::fromValues([
                $no++,
                $emp['code'],
                $emp['name'],
                $emp['department'],
                $emp['position'],
                $emp['level'],
                $item['scheduled_days'],
                $item['attended_days'],
                $item['scheduled_sessions'],
                $item['attended_sessions'],
                $item['verified_minutes'],
                $item['verified_hours_formatted'],
                $item['late_count'],
                $item['late_minutes'],
                $item['late_permitted_count'],
                $item['late_unpermitted_count'],
                $item['review_count'],
                $item['overtime_actual_minutes'],
                $item['overtime_actual_hours_formatted'],
                $item['overtime_minutes'],
                $item['overtime_hours_formatted'],
                $item['leave_days'],
                $item['meal_allowance'],
                $item['transport_allowance'],
                $item['total_allowance'],
            ]));
        }

        // Summary Total Row
        $writer->addRow(Row::fromValuesWithStyle([
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            '-',
            $kpi['total_attended_days'],
            $kpi['total_scheduled_sessions'],
            $kpi['total_attended_sessions'],
            $kpi['total_verified_minutes'],
            $kpi['total_verified_hours_formatted'],
            $kpi['total_late_count'],
            $kpi['total_late_minutes'],
            '-',
            '-',
            $kpi['total_review_count'],
            $kpi['total_actual_overtime_minutes'],
            $kpi['total_actual_overtime_hours_formatted'],
            $kpi['total_overtime_minutes'],
            $kpi['total_overtime_hours_formatted'],
            $kpi['total_leave_days'],
            $kpi['total_meal_allowance'],
            $kpi['total_transport_allowance'],
            $kpi['total_allowance_amount'],
        ], $totalRowStyle));

        // ======================= SHEET 2: DETAIL SESI =======================
        $writer->addNewSheetAndMakeItCurrent();
        $sheet2 = $writer->getCurrentSheet();
        $sheet2->setName('Detail Sesi');

        $writer->addRow(Row::fromValuesWithStyle([$siteName], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(['DETAIL KEHADIRAN DAN SESI KERJA'], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle([
            "Periode: {$filters['from']} s.d. {$filters['to']} | Status Rekap: {$periodStatus}",
        ], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));

        $detailHeaders = [
            'No',
            'Tanggal Kerja',
            'NIK',
            'Nama Karyawan',
            'Departemen',
            'Shift / Sesi',
            'Jadwal Shift',
            'Jam Masuk',
            'Jam Pulang',
            'Status Sesi',
            'Terlambat (Menit)',
            'Status Izin',
            'Menit Valid',
            'Durasi Valid',
            'Lembur Aktual (Menit)',
            'Lembur Diakui (Menit)',
            'Uang Makan (Rp)',
            'Uang Transport (Rp)',
            'Total Tunjangan (Rp)',
        ];
        $writer->addRow(Row::fromValuesWithStyle($detailHeaders, $headerStyle));

        $dNo = 1;
        foreach ($details as $row) {
            $emp = $row['employee'];
            $att = $row['attendance'];
            $sh = $row['shift'];
            $al = $row['allowance'];

            $writer->addRow(Row::fromValues([
                $dNo++,
                $row['work_date'],
                $emp['code'],
                $emp['name'],
                $emp['department'],
                $sh['name'].' - Sesi '.$sh['seq'],
                $sh['schedule_clock'],
                $att['check_in_clock'],
                $att['check_out_clock'],
                $att['state_label'],
                $att['late_minutes'],
                $att['late_permission_status'] ?? '-',
                $att['verified_minutes'],
                $att['verified_hours_formatted'],
                $row['overtime_actual_minutes'],
                $row['overtime_verified_minutes'],
                $al['meal_amount'],
                $al['transport_amount'],
                $al['total_amount'],
            ]));
        }

        // ======================= SHEET 3: FREKUENSI SHIFT =======================
        $writer->addNewSheetAndMakeItCurrent();
        $sheet3 = $writer->getCurrentSheet();
        $sheet3->setName('Frekuensi Shift');
        $writer->addRow(Row::fromValuesWithStyle([$siteName], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(['FREKUENSI SHIFT PER KARYAWAN'], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(["Periode: {$filters['from']} s.d. {$filters['to']} | Hanya shift dengan check-in"], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValuesWithStyle(['No', 'NIK', 'Nama Karyawan', 'Departemen', 'Kategori Shift', 'Jumlah Shift'], $headerStyle));

        $breakdownNo = 1;
        foreach ($shiftBreakdown as $item) {
            $employee = $item['employee'];
            $writer->addRow(Row::fromValues([
                $breakdownNo++,
                $employee['code'],
                $employee['name'],
                $employee['department'],
                $item['category_name'],
                $item['shift_count'],
            ]));
        }

        // ======================= SHEET 4: REKAP LEMBUR =======================
        $writer->addNewSheetAndMakeItCurrent();
        $sheet4 = $writer->getCurrentSheet();
        $sheet4->setName('Rekap Lembur');
        $writer->addRow(Row::fromValuesWithStyle([$siteName], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(['REKAP REALISASI LEMBUR'], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle([
            "Periode: {$filters['from']} s.d. {$filters['to']} | Hanya SPL disetujui | Status Rekap: {$periodStatus}",
        ], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValuesWithStyle([
            'No', 'No. SPL', 'Tanggal Kerja', 'NIK', 'Nama Karyawan', 'Departemen', 'Shift / Sesi',
            'Uraian Tugas', 'Rentang SPL', 'Rencana (Menit)', 'Aktual (Menit)', 'Diakui (Menit)', 'Status Realisasi',
        ], $headerStyle));

        $overtimeNo = 1;
        foreach ($overtime as $order) {
            $employee = $order['employee'] ?? [];
            $writer->addRow(Row::fromValues([
                $overtimeNo++,
                $order['order_number'],
                $order['work_date'],
                $employee['code'] ?? '',
                $order['employee_name'] ?? ($employee['name'] ?? ''),
                $order['department_name'] ?? ($employee['department'] ?? '-'),
                $order['session_name'] ?? ($order['is_day_off'] ? 'Lembur Hari Libur' : '-'),
                $order['title'],
                ($order['local_start'] ?? '-').' - '.($order['local_end'] ?? '-'),
                $order['planned_minutes'],
                $order['actual_minutes'],
                $order['verified_minutes'],
                $order['execution_status'] === 'REALIZED' ? 'Terealisasi' : 'Belum terealisasi',
            ]));
        }

        $writer->close();
    }
}
