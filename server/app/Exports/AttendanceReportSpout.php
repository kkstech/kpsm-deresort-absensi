<?php

namespace App\Exports;

use App\Models\SiteSetting;
use App\Support\WorkTime;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class AttendanceReportSpout
{
    public const SUMMARY_HEADERS = [
        'No',
        'Nama',
        'Tanggal Mulai Bekerja',
        'Jabatan',
        'Total Hari - Jadwal',
        'Total Hari - Hadir',
        'Total Sesi - Jadwal',
        'Total Sesi - Hadir',
        'Shift I',
        'Shift II',
        'Shift III',
        'Middle',
        'Split',
        'Tukar Shift',
        'Keterlambatan - Jumlah',
        'Keterlambatan - Durasi Aktual (Menit)',
        'Keterlambatan - Berizin',
        'Keterlambatan - Tanpa Izin',
        'Lembur - Aktual (Menit)',
        'Lembur - SPL (Menit)',
        'Cuti',
        'Deposit Off',
        'Tunjangan Makan (Rp)',
        'Tunjangan Transport (Rp)',
        'Total Tunjangan (Rp)',
    ];

    public function export(array $summary, iterable $details, iterable $shiftBreakdown, iterable $overtime, string $filePath): void
    {
        $site = SiteSetting::current();
        $siteName = $site->site_name ?? 'Sistem Absensi Resort';
        $filters = $summary['filters'];
        $periodStatus = $summary['period_status'];
        $kpi = $summary['kpi'];
        $rows = $summary['rows'];

        $options = new Options;
        $writer = new Writer($options);
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
        $sheet1->setColumnWidth(6, 1);
        $sheet1->setColumnWidth(30, 2);
        $sheet1->setColumnWidth(14, 3);
        $sheet1->setColumnWidth(20, 4);
        $sheet1->setColumnWidthForRange(13, 5, count(self::SUMMARY_HEADERS));
        $lastColumn = count(self::SUMMARY_HEADERS) - 1;

        $deptStyle = (new Style)
            ->withFontBold(true)
            ->withFontSize(10)
            ->withFontColor('FFFFFF')
            ->withBackgroundColor('0F172A');

        // Title Block
        $writer->addRow(Row::fromValuesWithStyle([$siteName], $titleStyle));
        $writer->addRow(Row::fromValuesWithStyle(['LAPORAN REKAPITULASI KEHADIRAN KARYAWAN'], $titleStyle));
        $periodLabel = $summary['period_label'] ?? "{$filters['from']} s.d. {$filters['to']}";
        $writer->addRow(Row::fromValuesWithStyle([
            "Periode: {$periodLabel} | Status Rekap: {$periodStatus} | Tanggal Cetak: ".WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
        ], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));

        // Kolom mengikuti template PDF rekap (tanpa NIK & Departemen; departemen jadi judul grup).
        $writer->addRow(Row::fromValuesWithStyle(self::SUMMARY_HEADERS, $headerStyle));

        $no = 1;
        $sheetRow = 5; // judul (2) + periode + baris kosong + header
        foreach ($summary['groups'] ?? [['department' => '-', 'rows' => $rows]] as $group) {
            $writer->addRow(Row::fromValuesWithStyle(['Departemen : '.$group['department']], $deptStyle));
            $sheetRow++;
            $options->mergeCells(0, $sheetRow, $lastColumn, $sheetRow, 0);
            foreach ($group['rows'] as $item) {
                $emp = $item['employee'];
                $counts = $item['shift_counts'] ?? [];
                $writer->addRow(Row::fromValues([
                    $no++,
                    $emp['name'],
                    $emp['started_on_formatted'] ?? '-',
                    $emp['position'],
                    $item['scheduled_days'],
                    $item['attended_days'],
                    $item['scheduled_sessions'],
                    $item['attended_sessions'],
                    $counts['shift_1'] ?? 0,
                    $counts['shift_2'] ?? 0,
                    $counts['shift_3'] ?? 0,
                    $counts['middle'] ?? 0,
                    $counts['split'] ?? 0,
                    $counts['exchange'] ?? 0,
                    $item['late_count'],
                    $item['late_minutes'],
                    $item['late_permitted_count'],
                    $item['late_unpermitted_count'],
                    $item['overtime_actual_minutes'],
                    $item['overtime_minutes'],
                    $item['cuti_days'] ?? 0,
                    $item['deposit_off_days'] ?? 0,
                    $item['meal_allowance'],
                    $item['transport_allowance'],
                    $item['total_allowance'],
                ]));
                $sheetRow++;
            }
        }

        $collection = collect($rows);
        $options->mergeCells(0, $sheetRow + 1, 3, $sheetRow + 1, 0);
        $writer->addRow(Row::fromValuesWithStyle([
            'TOTAL KESELURUHAN ('.($kpi['total_employees'] ?? count($rows)).' karyawan)',
            '',
            '',
            '',
            $kpi['total_scheduled_days'],
            $kpi['total_attended_days'],
            $kpi['total_scheduled_sessions'],
            $kpi['total_attended_sessions'],
            $kpi['total_shift_1'] ?? 0,
            $kpi['total_shift_2'] ?? 0,
            $kpi['total_shift_3'] ?? 0,
            $kpi['total_middle'] ?? 0,
            $kpi['total_split'] ?? 0,
            $kpi['total_exchange'] ?? 0,
            $kpi['total_late_count'],
            $kpi['total_late_minutes'],
            $collection->sum('late_permitted_count'),
            $collection->sum('late_unpermitted_count'),
            $kpi['total_actual_overtime_minutes'],
            $kpi['total_overtime_minutes'],
            $kpi['total_cuti_days'] ?? 0,
            $kpi['total_deposit_off_days'] ?? 0,
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
            'Sumber Checkout',
            'Checkout Otomatis Awal',
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
                $att['checkout_source_label'] ?? '-',
                $att['auto_checkout_clock'] ?? '-',
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
        $writer->addRow(Row::fromValuesWithStyle(["Periode: {$filters['from']} s.d. {$filters['to']} | Rekapitulasi jenis shift kehadiran"], $subTitleStyle));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValuesWithStyle([
            'No', 'NIK', 'Nama Karyawan', 'Departemen',
            'Shift 1', 'Shift 2', 'Shift 3', 'Middle', 'Split Khusus', 'Tukar Shift', 'Total Hadir',
        ], $headerStyle));

        $breakdownNo = 1;
        foreach ($rows as $item) {
            $employee = $item['employee'];
            $counts = $item['shift_counts'] ?? [];
            $writer->addRow(Row::fromValues([
                $breakdownNo++,
                $employee['code'],
                $employee['name'],
                $employee['department'],
                $counts['shift_1'] ?? 0,
                $counts['shift_2'] ?? 0,
                $counts['shift_3'] ?? 0,
                $counts['middle'] ?? 0,
                $counts['split'] ?? 0,
                $counts['exchange'] ?? 0,
                $counts['total_shifts'] ?? 0,
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
