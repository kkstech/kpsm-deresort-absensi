<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Realisasi Lembur</title>
    <style>
        @page { margin: 12mm 10mm 15mm; size: a4 landscape; }
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #1e293b; font-size: 7.5pt; line-height: 1.25; }
        .watermark { position: fixed; top: 35%; left: 20%; transform: rotate(-25deg); font-size: 65pt; font-weight: bold; color: rgba(239, 68, 68, .13); z-index: -1000; letter-spacing: 8px; }
        .header { margin-bottom: 12px; border-bottom: 2px solid #0f172a; padding-bottom: 8px; }
        .resort-name { color: #0f172a; font-size: 14pt; font-weight: bold; text-transform: uppercase; }
        .report-title { color: #334155; font-size: 11pt; font-weight: bold; margin-top: 2px; }
        .meta { color: #64748b; font-size: 7.5pt; margin-top: 4px; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f1f5f9; border: 1px solid #cbd5e1; color: #0f172a; font-size: 6.5pt; padding: 5px 4px; text-align: center; text-transform: uppercase; }
        .data-table td { border: 1px solid #e2e8f0; font-size: 6.5pt; padding: 4px; vertical-align: middle; }
        .data-table tbody tr:nth-child(even) td { background: #fafafa; }
        .center { text-align: center; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 6.5pt; padding-top: 4px; }
    </style>
</head>
<body>
    @if ($periodStatus !== 'FINALIZED')
        <div class="watermark">PROVISIONAL</div>
    @endif

    <header class="header">
        <div class="resort-name">{{ $site->site_name ?? 'SISTEM ABSENSI RESORT' }}</div>
        <div class="report-title">REKAP REALISASI LEMBUR</div>
        <div class="meta">
            Periode: {{ $filters['from'] }} s.d. {{ $filters['to'] }}
            @if (! empty($filters['department_name'])) · Departemen: {{ $filters['department_name'] }} @endif
            · Status rekap: {{ $periodStatus }} · Dicetak: {{ $generatedAtLocal }}
        </div>
        <div class="meta">Aktual berasal dari durasi kerja yang tercatat. Durasi diakui mengikuti perhitungan SPL yang disetujui.</div>
    </header>

    <table class="data-table">
        <thead>
            <tr>
                <th>No</th>
                <th>No. SPL</th>
                <th>Tanggal</th>
                <th>Karyawan</th>
                <th>Departemen</th>
                <th>Shift / Sesi</th>
                <th>Uraian Tugas</th>
                <th>Rentang SPL</th>
                <th>Rencana</th>
                <th>Aktual</th>
                <th>Diakui</th>
                <th>Realisasi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $index => $order)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $order['order_number'] }}</td>
                    <td class="center">{{ $order['work_date'] }}</td>
                    <td>{{ $order['employee_name'] ?? ($order['employee']['name'] ?? '-') }}</td>
                    <td>{{ $order['department_name'] ?? ($order['employee']['department'] ?? '-') }}</td>
                    <td>{{ $order['session_name'] ?? ($order['is_day_off'] ? 'Lembur Hari Libur' : '-') }}</td>
                    <td>{{ $order['title'] }}</td>
                    <td class="center">{{ $order['local_start'] ?? '-' }} - {{ $order['local_end'] ?? '-' }}</td>
                    <td class="center">{{ $order['planned_minutes'] }} m</td>
                    <td class="center">{{ $order['actual_minutes'] }} m</td>
                    <td class="center">{{ $order['verified_minutes'] }} m</td>
                    <td class="center">{{ $order['execution_status'] === 'REALIZED' ? 'Terealisasi' : 'Belum terealisasi' }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="center">Belum ada SPL disetujui pada periode dan filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer class="footer">{{ $site->site_name ?? 'Sistem Absensi Resort' }} · Rekap lembur · Dokumen internal</footer>
</body>
</html>
