<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Outstanding Cuti &amp; Deposit Off</title>
    <style>
        @page {
            margin: 10mm 8mm 15mm 8mm;
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
            font-size: 6pt;
            padding: 2px 2px;
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
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 70%;">
                    <div class="resort-name">{{ $site->site_name ?? 'SISTEM ABSENSI RESORT' }}</div>
                    <div class="report-title">LAPORAN OUTSTANDING CUTI &amp; DEPOSIT OFF KARYAWAN</div>
                    <div class="meta-text">
                        Periode: <strong>{{ $report['period_label'] }}</strong>
                        @if (! empty($filters['department_name']))
                            | Departemen: <strong>{{ $filters['department_name'] }}</strong>
                        @endif
                        | Total karyawan: <strong>{{ $report['total_employees'] }}</strong>
                    </div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: top;">
                    <div class="meta-text">Dicetak: {{ $report['generated_at_local'] }}</div>
                    <div class="meta-text">C = Cuti, DO = Deposit Off (hari)</div>
                </td>
            </tr>
        </table>
    </div>

    @php
        $dash = fn ($v) => (int) $v !== 0 ? $v : '-';
    @endphp
    <table class="data-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 14px;">No</th>
                <th rowspan="2">Nama</th>
                <th rowspan="2" style="width: 40px;">Tanggal Mulai Bekerja</th>
                <th rowspan="2" style="width: 52px;">Jabatan</th>
                <th colspan="2">Saldo Awal</th>
                @foreach ($report['month_short_names'] as $month => $monthName)
                    <th colspan="2" title="{{ $report['month_names'][$month] }}">{{ $monthName }}</th>
                @endforeach
                <th colspan="2">Saldo Akhir</th>
            </tr>
            <tr>
                @for ($i = 0; $i < 14; $i++)
                    <th class="sub" style="width: 13px;">C</th>
                    <th class="sub" style="width: 13px;">DO</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @php $no = 0; @endphp
            @forelse ($report['groups'] as $group)
                <tr class="dept-row">
                    <td colspan="32">Departemen : {{ $group['department'] }}</td>
                </tr>
                @foreach ($group['rows'] as $row)
                    @php $emp = $row['employee']; @endphp
                    <tr>
                        <td class="text-center">{{ ++$no }}</td>
                        <td class="text-left"><strong>{{ $emp['name'] }}</strong></td>
                        <td class="text-center nowrap">{{ $emp['started_on_formatted'] ?? '-' }}</td>
                        <td class="text-left">{{ $emp['position'] }}</td>
                        <td class="text-center">{{ $row['opening_cuti'] }}</td>
                        <td class="text-center">{{ $row['opening_deposit_off'] }}</td>
                        @foreach ($row['months'] as $used)
                            <td class="text-center">{{ $dash($used['cuti']) }}</td>
                            <td class="text-center">{{ $dash($used['deposit_off']) }}</td>
                        @endforeach
                        <td class="text-center"><strong>{{ $row['closing_cuti'] }}</strong></td>
                        <td class="text-center"><strong>{{ $row['closing_deposit_off'] }}</strong></td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="32" class="text-center" style="padding: 15px; color: #64748b;">
                        Tidak ada karyawan aktif untuk filter ini.
                    </td>
                </tr>
            @endforelse
            @if (! empty($report['rows']))
                @php $t = $report['totals']; @endphp
                <tr class="total-row">
                    <td colspan="4" class="text-center">TOTAL KESELURUHAN</td>
                    <td class="text-center">{{ $t['opening_cuti'] }}</td>
                    <td class="text-center">{{ $t['opening_deposit_off'] }}</td>
                    @foreach ($t['months'] as $used)
                        <td class="text-center">{{ $used['cuti'] }}</td>
                        <td class="text-center">{{ $used['deposit_off'] }}</td>
                    @endforeach
                    <td class="text-center">{{ $t['closing_cuti'] }}</td>
                    <td class="text-center">{{ $t['closing_deposit_off'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="meta-text">
        Saldo awal = hak cuti tahun {{ $report['year'] }} (kuota tahunan, prorata untuk karyawan yang mulai bekerja tahun ini, ditambah penyesuaian).
        Pemakaian = cuti berstatus disetujui, dihitung per hari kalender. Saldo akhir = saldo awal - pemakaian; pengajuan yang masih menunggu belum mengurangi saldo.
    </div>

    <div class="footer">
        {{ $site->site_name ?? 'Sistem Absensi Resort' }} &bull; Laporan Outstanding Cuti &amp; Deposit Off &bull; Dokumen Rahasia Perusahaan
    </div>
</body>
</html>
