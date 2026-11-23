<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Rekapitulasi Kehadiran</title>
    <style>
        @page {
            margin: 12mm 10mm 15mm 10mm;
            size: a4 landscape;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 8pt;
            color: #1e293b;
            line-height: 1.25;
            margin: 0;
            padding: 0;
        }
        .watermark {
            position: fixed;
            top: 35%;
            left: 20%;
            transform: rotate(-25deg);
            font-size: 65pt;
            font-weight: bold;
            color: rgba(239, 68, 68, 0.13);
            z-index: -1000;
            text-transform: uppercase;
            letter-spacing: 8px;
            pointer-events: none;
        }
        .header {
            margin-bottom: 12px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .resort-name {
            font-size: 14pt;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .report-title {
            font-size: 11pt;
            font-weight: bold;
            color: #334155;
            margin-top: 2px;
        }
        .meta-text {
            font-size: 7.5pt;
            color: #64748b;
            margin-top: 4px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 7.5pt;
            font-weight: bold;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .badge-final {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .badge-provisional {
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fcd34d;
        }
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .kpi-table td {
            padding: 6px 10px;
            border-right: 1px solid #e2e8f0;
            text-align: center;
        }
        .kpi-table td:last-child {
            border-right: none;
        }
        .kpi-label {
            font-size: 6.5pt;
            color: #64748b;
            text-transform: uppercase;
            font-weight: bold;
        }
        .kpi-val {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-size: 6pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 4px 2px;
            border: 1px solid #cbd5e1;
            text-align: center;
        }
        .data-table td {
            font-size: 6.5pt;
            padding: 3px 3px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .data-table tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .data-table th.sub { font-size: 5.5pt; padding: 3px 2px; }
        .dept-row td {
            background-color: #0f172a !important;
            color: #ffffff;
            font-weight: bold;
            font-size: 7pt;
            text-align: left;
            padding: 4px 6px;
        }
        .nowrap { white-space: nowrap; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .total-row td {
            background-color: #e2e8f0 !important;
            font-weight: bold;
            border-top: 2px solid #94a3b8;
            color: #0f172a;
        }
        .signatures {
            margin-top: 20px;
            width: 100%;
            border-collapse: collapse;
        }
        .signatures td {
            width: 33%;
            text-align: center;
            vertical-align: top;
            font-size: 7.5pt;
        }
        .sign-space {
            height: 45px;
        }
        .sign-name {
            font-weight: bold;
            text-decoration: underline;
        }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            font-size: 6.5pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
        }
    </style>
</head>
<body>
    @if ($summary['period_status'] !== 'FINALIZED')
        <div class="watermark">PROVISIONAL</div>
    @endif

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 70%;">
                    <div class="resort-name">{{ $site->site_name ?? 'SISTEM ABSENSI RESORT' }}</div>
                    <div class="report-title">LAPORAN REKAPITULASI KEHADIRAN KARYAWAN</div>
                    <div class="meta-text">
                        Periode: <strong>{{ $summary['period_label'] ?? ($summary['filters']['from'].' s.d. '.$summary['filters']['to']) }}</strong>
                        @if (! empty($summary['filters']['department_name']))
                            | Departemen: <strong>{{ $summary['filters']['department_name'] }}</strong>
                        @endif
                    </div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: top;">
                    <div>
                        @if ($summary['period_status'] === 'FINALIZED')
                            <span class="badge badge-final">REKAP RESMI (FINAL)</span>
                        @else
                            <span class="badge badge-provisional">DRAFT (PROVISIONAL)</span>
                        @endif
                    </div>
                    <div class="meta-text" style="margin-top: 6px;">
                        Dicetak: {{ $summary['generated_at_local'] }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @php $kpi = $summary['kpi']; @endphp
    <table class="kpi-table">
        <tr>
            <td>
                <div class="kpi-label">Total Karyawan</div>
                <div class="kpi-val">{{ number_format($kpi['total_employees']) }}</div>
            </td>
            <td>
                <div class="kpi-label">Total Hari Hadir</div>
                <div class="kpi-val">{{ number_format($kpi['total_attended_days']) }}</div>
            </td>
            <td>
                <div class="kpi-label">Jam Reguler Valid</div>
                <div class="kpi-val">{{ $kpi['total_verified_hours_formatted'] }}</div>
            </td>
            <td>
                <div class="kpi-label">Jam Lembur Aktual</div>
                <div class="kpi-val">{{ $kpi['total_actual_overtime_hours_formatted'] }}</div>
            </td>
            <td>
                <div class="kpi-label">Jam Lembur Diakui</div>
                <div class="kpi-val">{{ $kpi['total_overtime_hours_formatted'] }}</div>
            </td>
            <td>
                <div class="kpi-label">Total Keterlambatan</div>
                <div class="kpi-val">{{ number_format($kpi['total_late_count']) }}x ({{ number_format($kpi['total_late_minutes']) }} m)</div>
            </td>
            <td>
                <div class="kpi-label">Total Tunjangan</div>
                <div class="kpi-val">Rp {{ number_format($kpi['total_allowance_amount'], 0, ',', '.') }}</div>
            </td>
            <td>
                <div class="kpi-label">Tingkat Kehadiran</div>
                <div class="kpi-val">{{ $kpi['attendance_rate'] }}%</div>
            </td>
        </tr>
    </table>

    @php
        $rp = fn ($v) => number_format((int) $v, 0, ',', '.');
        $dash = fn ($v) => (int) $v > 0 ? $v : '-';
    @endphp
    <table class="data-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 16px;">No</th>
                <th rowspan="2">Nama</th>
                <th rowspan="2" style="width: 44px;">Tanggal Mulai Bekerja</th>
                <th rowspan="2" style="width: 60px;">Jabatan</th>
                <th colspan="2">Total Hari</th>
                <th colspan="2">Total Sesi</th>
                <th colspan="5">Shift</th>
                <th rowspan="2" style="width: 26px;">Tukar Shift</th>
                <th colspan="4">Keterlambatan</th>
                <th colspan="2">Lembur</th>
                <th rowspan="2" style="width: 22px;">Cuti</th>
                <th rowspan="2" style="width: 26px;">Deposit Off</th>
                <th rowspan="2" style="width: 48px;">Tunjangan Makan</th>
                <th rowspan="2" style="width: 48px;">Tunjangan Transport</th>
                <th rowspan="2" style="width: 52px;">Total Tunjangan</th>
            </tr>
            <tr>
                <th class="sub" style="width: 22px;">Jadwal</th>
                <th class="sub" style="width: 22px;">Hadir</th>
                <th class="sub" style="width: 22px;">Jadwal</th>
                <th class="sub" style="width: 22px;">Hadir</th>
                <th class="sub" style="width: 16px;">I</th>
                <th class="sub" style="width: 16px;">II</th>
                <th class="sub" style="width: 16px;">III</th>
                <th class="sub" style="width: 24px;">Middle</th>
                <th class="sub" style="width: 20px;">Split</th>
                <th class="sub" style="width: 20px;">Jml</th>
                <th class="sub" style="width: 30px;">Durasi Aktual</th>
                <th class="sub" style="width: 24px;">Berizin</th>
                <th class="sub" style="width: 26px;">Tanpa Izin</th>
                <th class="sub" style="width: 32px;">Aktual</th>
                <th class="sub" style="width: 32px;">SPL</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 0; @endphp
            @forelse ($summary['groups'] ?? [] as $group)
                <tr class="dept-row">
                    <td colspan="25">Departemen : {{ $group['department'] }}</td>
                </tr>
                @foreach ($group['rows'] as $row)
                    @php $emp = $row['employee']; @endphp
                    @php $sc = $row['shift_counts'] ?? []; @endphp
                    <tr>
                        <td class="text-center">{{ ++$no }}</td>
                        <td class="text-left"><strong>{{ $emp['name'] }}</strong></td>
                        <td class="text-center nowrap">{{ $emp['started_on_formatted'] ?? '-' }}</td>
                        <td class="text-left">{{ $emp['position'] }}</td>
                        <td class="text-center">{{ $row['scheduled_days'] }}</td>
                        <td class="text-center"><strong>{{ $row['attended_days'] }}</strong></td>
                        <td class="text-center">{{ $row['scheduled_sessions'] }}</td>
                        <td class="text-center"><strong>{{ $row['attended_sessions'] }}</strong></td>
                        <td class="text-center">{{ $dash($sc['shift_1'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($sc['shift_2'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($sc['shift_3'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($sc['middle'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($sc['split'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($sc['exchange'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($row['late_count']) }}</td>
                        <td class="text-center nowrap">{{ $row['late_minutes'] > 0 ? $row['late_minutes'].' m' : '-' }}</td>
                        <td class="text-center">{{ $dash($row['late_permitted_count']) }}</td>
                        <td class="text-center">{{ $dash($row['late_unpermitted_count']) }}</td>
                        <td class="text-center nowrap">{{ $row['overtime_actual_minutes'] > 0 ? $row['overtime_actual_hours_formatted'] : '-' }}</td>
                        <td class="text-center nowrap">{{ $row['overtime_minutes'] > 0 ? $row['overtime_hours_formatted'] : '-' }}</td>
                        <td class="text-center">{{ $dash($row['cuti_days'] ?? 0) }}</td>
                        <td class="text-center">{{ $dash($row['deposit_off_days'] ?? 0) }}</td>
                        <td class="text-right nowrap">{{ $rp($row['meal_allowance']) }}</td>
                        <td class="text-right nowrap">{{ $rp($row['transport_allowance']) }}</td>
                        <td class="text-right nowrap" style="font-weight: bold;">{{ $rp($row['total_allowance']) }}</td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="25" class="text-center" style="padding: 15px; color: #64748b;">
                        Tidak ada data kehadiran untuk periode dan filter ini.
                    </td>
                </tr>
            @endforelse
            @if (! empty($summary['rows']))
                <tr class="total-row">
                    <td colspan="4" class="text-center">TOTAL KESELURUHAN ({{ $kpi['total_employees'] }} karyawan)</td>
                    <td class="text-center">{{ $kpi['total_scheduled_days'] }}</td>
                    <td class="text-center">{{ $kpi['total_attended_days'] }}</td>
                    <td class="text-center">{{ $kpi['total_scheduled_sessions'] }}</td>
                    <td class="text-center">{{ $kpi['total_attended_sessions'] }}</td>
                    <td class="text-center">{{ $kpi['total_shift_1'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_shift_2'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_shift_3'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_middle'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_split'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_exchange'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_late_count'] }}</td>
                    <td class="text-center nowrap">{{ $kpi['total_late_minutes'] }} m</td>
                    <td class="text-center">{{ collect($summary['rows'])->sum('late_permitted_count') }}</td>
                    <td class="text-center">{{ collect($summary['rows'])->sum('late_unpermitted_count') }}</td>
                    <td class="text-center nowrap">{{ $kpi['total_actual_overtime_hours_formatted'] }}</td>
                    <td class="text-center nowrap">{{ $kpi['total_overtime_hours_formatted'] }}</td>
                    <td class="text-center">{{ $kpi['total_cuti_days'] ?? 0 }}</td>
                    <td class="text-center">{{ $kpi['total_deposit_off_days'] ?? 0 }}</td>
                    <td class="text-right nowrap">{{ $rp($kpi['total_meal_allowance']) }}</td>
                    <td class="text-right nowrap">{{ $rp($kpi['total_transport_allowance']) }}</td>
                    <td class="text-right nowrap">{{ $rp($kpi['total_allowance_amount']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div>Disiapkan Oleh,</div>
                <div class="sign-space"></div>
                <div class="sign-name">HRD / Payroll Officer</div>
            </td>
            <td>
                <div>Diperiksa Oleh,</div>
                <div class="sign-space"></div>
                <div class="sign-name">Accounting &amp; Finance</div>
            </td>
            <td>
                <div>Disetujui Oleh,</div>
                <div class="sign-space"></div>
                <div class="sign-name">General Manager / Resort Manager</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $site->site_name ?? 'Sistem Absensi Resort' }} &bull; Laporan Rekapitulasi &bull; Dokumen Rahasia Perusahaan
    </div>
</body>
</html>
