<div>
    <!-- Do what you can, with what you have, where you are. - Theodore Roosevelt -->
</div>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Frekuensi Shift</title>
    <style>
        @page { margin: 12mm; size: a4 landscape; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #172033; font-size: 9pt; }
        .header { border-bottom: 2px solid #1e293b; margin-bottom: 14px; padding-bottom: 8px; }
        .site { font-size: 15pt; font-weight: bold; text-transform: uppercase; }
        .title { font-size: 12pt; font-weight: bold; margin-top: 4px; }
        .meta { color: #64748b; font-size: 8pt; margin-top: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #eef2f7; color: #172033; text-transform: uppercase; font-size: 8pt; }
        th, td { border: 1px solid #cbd5e1; padding: 7px 8px; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .center { text-align: center; }
        .right { text-align: right; }
        .footer { position: fixed; bottom: -6mm; left: 0; right: 0; color: #94a3b8; font-size: 7pt; border-top: 1px solid #e2e8f0; padding-top: 4px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="site">{{ $site->site_name ?? 'SISTEM ABSENSI RESORT' }}</div>
        <div class="title">FREKUENSI SHIFT PER KARYAWAN</div>
        <div class="meta">
            Periode: {{ $filters['from'] }} s.d. {{ $filters['to'] }}
            @if (! empty($filters['department_name'])) · Departemen: {{ $filters['department_name'] }} @endif
            · Status rekap: {{ $periodStatus }} · Dicetak: {{ $generatedAtLocal }}
        </div>
        <div class="meta">Satu shift dihitung jika setidaknya satu sesi memiliki check-in. Shift dengan beberapa sesi tetap dihitung satu kali.</div>
    </header>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 14%;">NIK</th>
                <th>Karyawan</th>
                <th>Departemen</th>
                <th>Kategori Shift</th>
                <th style="width: 12%;">Jumlah Shift Hadir</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $row['employee']['code'] }}</td>
                    <td>{{ $row['employee']['name'] }}</td>
                    <td>{{ $row['employee']['department'] }}</td>
                    <td>{{ $row['category_name'] }}</td>
                    <td class="center">{{ $row['shift_count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center">Belum ada shift yang memiliki check-in pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer class="footer">Laporan frekuensi shift · Dicetak {{ $generatedAtLocal }}</footer>
</body>
</html>
