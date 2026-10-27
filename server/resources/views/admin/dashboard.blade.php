@php($siteSettings = \App\Models\SiteSetting::current())
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteSettings->site_name }}</title>
    @if ($siteSettings->favicon_url)
        <link rel="icon" href="{{ $siteSettings->favicon_url }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php($me = auth()->user())
<body data-role="{{ $me->role }}" data-user-id="{{ $me->id }}" data-today="{{ \App\Support\WorkTime::today() }}">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
        <symbol id="i-dashboard" viewBox="0 0 24 24"><path d="M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><path d="M4 6h16v14H4zM4 10h16M8 3v6M16 3v6"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 7"/></symbol>
        <symbol id="i-inbox" viewBox="0 0 24 24"><path d="M4 13l3-8h10l3 8v6H4zM4 13h5l1 2h4l1-2h5"/></symbol>
        <symbol id="i-people" viewBox="0 0 24 24"><path d="M9 11a4 4 0 100-8 4 4 0 000 8zM2 21v-2a5 5 0 015-5h4a5 5 0 015 5v2M17 4a4 4 0 010 7M22 21v-2a5 5 0 00-3-4.6"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h10M18 7h2M4 17h2M10 17h10M16 4v6M8 14v6"/></symbol>
        <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 17v-6a6 6 0 0112 0v6l2 2H4zM10 21h4"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path d="M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15zM16 16l5 5"/></symbol>
        <symbol id="i-shield-check" viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11zM9 12l2 2 4-4"/></symbol>
        <symbol id="i-building" viewBox="0 0 24 24"><path d="M3 21h18M5 21V5l7-3 7 3v16M9 8h.01M15 8h.01M9 12h.01M15 12h.01M10 21v-5h4v5"/></symbol>
        <symbol id="i-briefcase" viewBox="0 0 24 24"><path d="M3 8h18v12H3zM8 8V5h8v3M3 13h18M10 13v2h4v-2"/></symbol>
        <symbol id="i-map-pin" viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1116 0zM12 10a2 2 0 100-4 2 2 0 000 4z"/></symbol>
        <symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21a8 8 0 00-16 0M12 13a5 5 0 100-10 5 5 0 000 10z"/></symbol>
    </svg>

    <a class="skip-link" href="#main-content">Lewati ke konten</a>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
            <div class="brand">
                <img class="brand-logo" data-site-logo src="{{ $siteSettings->logo_url ?? '' }}" alt="" @if (! $siteSettings->logo_url) hidden @endif>
                <span><strong data-site-name>{{ $siteSettings->site_name }}</strong></span>
            </div>
            <nav class="nav-list">
                <button type="button" class="nav-item active" data-view="dashboard" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#i-dashboard"/></svg>Dashboard</button>
                <button type="button" class="nav-item" data-view="schedule"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>Jadwal &amp; Shift</button>
                <button type="button" class="nav-item" data-view="attendance"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>Monitoring Absensi</button>
                <button type="button" class="nav-item" data-view="requests"><svg class="icon" aria-hidden="true"><use href="#i-inbox"/></svg>Persetujuan</button>
                <button type="button" class="nav-item" data-view="employees"><svg class="icon" aria-hidden="true"><use href="#i-people"/></svg>Karyawan</button>
                <button type="button" class="nav-item" data-view="leaves" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>Cuti</button>
                <button type="button" class="nav-item" data-view="allowances" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg>Tunjangan</button>
                <button type="button" class="nav-item" data-view="reports"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg>Laporan</button>
            </nav>
            <div class="sidebar-bottom">
                <button type="button" class="nav-item" data-view="settings" data-requires="web_admin"><svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg>Pengaturan</button>
                <div class="operator">
                    <span class="avatar">{{ \Illuminate\Support\Str::of($me->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
                    <span><strong>{{ $me->name }}</strong><small>{{ ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'supervisor' => 'Supervisor'][$me->role] ?? $me->role }}</small></span>
                </div>
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-item logout-button">Keluar</button></form>
            </div>
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <button type="button" class="icon-button menu-button" id="menu-button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="sidebar">☰</button>
                <div class="search-wrap">
                    <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                    <label class="sr-only" for="global-search">Cari karyawan</label>
                    <input id="global-search" type="search" list="employee-list" placeholder="Cari karyawan, lihat jadwalnya" autocomplete="off">
                    <datalist id="employee-list"></datalist>
                    <kbd>⌘ K</kbd>
                </div>
                <div class="top-actions">
                    <span class="server-state"><i></i>Server lokal aktif</span>
                    <button type="button" class="icon-button notification-button" data-view-link="requests" aria-label="Buka persetujuan"><svg class="icon" aria-hidden="true"><use href="#i-bell"/></svg></button>
                </div>
            </header>

            <main id="main-content" tabindex="-1">
                <section class="view active" data-view-panel="dashboard">
                    <div class="page-heading">
                        <div><p data-site-name>{{ $siteSettings->site_name }}</p><h1>Dashboard</h1><span id="dashboard-finding">Ringkasan kehadiran dan sesi yang perlu ditinjau.</span></div>
                    </div>
                    <form class="attendance-filters panel" id="dashboard-filters">
                        <label>Dari<input type="date" name="from" required></label>
                        <label>Sampai<input type="date" name="to" required></label>
                        <label>Departemen<select name="department_id"><option value="">Semua departemen</option></select></label>
                        <label>Karyawan<select name="employee_id"><option value="">Semua karyawan</option></select></label>
                        <button type="submit" class="secondary-button">Terapkan</button>
                    </form>
                    <p class="form-error" id="dashboard-error" role="alert" hidden></p>
                    <section class="metric-grid" aria-label="Ringkasan kehadiran" aria-live="polite">
                        <div class="metric-card"><span>Karyawan dijadwalkan</span><strong id="metric-employees">—</strong><small>unik dalam rentang</small></div>
                        <div class="metric-card"><span>Sesi check-in</span><strong id="metric-checkins">—</strong><small id="metric-sessions">— sesi dijadwalkan</small></div>
                        <div class="metric-card warning"><span>Perlu review</span><strong id="metric-review">—</strong><small>sesi atau event ditandai</small></div>
                        <div class="metric-card"><span>Menit tervalidasi</span><strong id="metric-minutes">—</strong><small>jam kerja yang dihitung</small></div>
                    </section>
                    <section class="panel attendance-panel" aria-labelledby="activity-title">
                        <div class="panel-heading"><div><h2 id="activity-title">Aktivitas absensi terbaru</h2><p>Perubahan dari event absensi yang tercatat.</p></div><button type="button" class="text-button" data-view-link="attendance">Buka monitoring</button></div>
                        <div id="dashboard-activity" aria-live="polite"><p class="list-empty">Memuat data…</p></div>
                    </section>
                </section>

                <section class="view schedule-view" data-view-panel="schedule">
                    <div class="page-heading">
                        <div><p>Perencanaan shift</p><h1>Jadwal &amp; Shift</h1><span>Pilih tampilan mingguan atau bulanan. Pilih karyawan untuk melihat jadwalnya saja.</span></div>
                        <div class="heading-actions" data-requires="admin">
                            <button type="button" class="secondary-button" id="schedule-copy">Salin ke minggu depan</button>
                            <button type="button" class="secondary-button" id="schedule-publish">Terbitkan draft</button>
                            <button type="button" class="primary-button" data-open-modal="schedule-modal">+ Buat jadwal</button>
                        </div>
                    </div>
                    <p class="form-error" id="schedule-load-error" role="alert" hidden></p>
                    <div class="calendar-layout">
                        <section class="panel calendar-panel" aria-label="Jadwal karyawan">
                            <div class="schedule-toolbar">
                                <div class="range"><h2 id="range-label" aria-live="polite"></h2><span id="range-count"></span></div>
                                <div class="toolbar-controls">
                                    <label class="person-select"><span>Karyawan</span><select id="person-filter"></select></label>
                                    <div class="view-toggle" role="group" aria-label="Tampilan kalender">
                                        <button type="button" data-cal-view="week" aria-pressed="true">Mingguan</button>
                                        <button type="button" data-cal-view="month" aria-pressed="false">Bulanan</button>
                                    </div>
                                    <div class="calendar-controls">
                                        <button type="button" class="secondary-button today-button" id="calendar-today">Hari ini</button>
                                        <button type="button" class="icon-button calendar-nav" id="calendar-prev" aria-label="Minggu sebelumnya">‹</button>
                                        <button type="button" class="icon-button calendar-nav" id="calendar-next" aria-label="Minggu berikutnya">›</button>
                                    </div>
                                </div>
                            </div>
                            <div id="calendar-body"></div>
                            <div class="calendar-legend"><span><i class="legend-dot regular"></i>Reguler</span><span><i class="legend-dot special"></i>Khusus</span><span><i class="legend-dot night"></i>Malam</span></div>
                        </section>
                        <aside class="panel selected-day-panel" aria-live="polite">
                            <div class="selected-day-heading"><div><span id="selected-person-label"></span><h2 id="selected-date-label"></h2></div></div>
                            <div id="selected-day-list" class="selected-day-list"></div>
                            <button type="button" class="secondary-button selected-add-button" id="add-selected-date" data-requires="admin">+ Tambah jadwal</button>
                        </aside>
                    </div>
                    <p class="calendar-help">Jadwal bisa diubah sampai sesi pertama mulai. Setelah itu, ubah lewat koreksi.</p>
                </section>
                <section class="view attendance-view" data-view-panel="attendance">
                    <div class="page-heading"><div><p data-site-name>{{ $siteSettings->site_name }}</p><h1>Monitoring Absensi</h1><span>Catatan per sesi, termasuk event offline dan checkout provisional.</span></div></div>
                    <form class="attendance-filters panel" id="monitoring-filters">
                        <label>Dari<input type="date" name="from" required></label>
                        <label>Sampai<input type="date" name="to" required></label>
                        <label>Departemen<select name="department_id"><option value="">Semua departemen</option></select></label>
                        <label>Karyawan<select name="employee_id"><option value="">Semua karyawan</option></select></label>
                        <button type="submit" class="secondary-button">Terapkan</button>
                    </form>
                    <p class="form-error" id="monitoring-error" role="alert" hidden></p>
                    <section class="panel monitoring-panel" aria-label="Daftar absensi"><div class="panel-heading"><div><h2>Absensi per sesi</h2><p id="monitoring-count" aria-live="polite">Memuat data…</p></div></div><div id="monitoring-table" aria-live="polite"><p class="list-empty">Memuat data…</p></div><div id="monitoring-pagination" class="table-pagination"></div></section>
                </section>
                <section class="view workflow-view" data-view-panel="requests">
                    <div class="page-heading">
                        <div>
                            <p data-site-name>{{ $siteSettings->site_name }}</p>
                            <h1>Persetujuan</h1>
                            <span>Permintaan izin dan koreksi menunggu keputusan.</span>
                        </div>
                        <button type="button" class="secondary-button workflow-refresh-btn" id="workflow-refresh">
                            <svg class="icon" aria-hidden="true" style="width: 16px; height: 16px;"><path d="M1 4v6h6M23 20v-6h-6M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                            <span>Muat ulang</span>
                        </button>
                    </div>
                    <p class="form-error" id="workflow-error" role="alert" hidden></p>
                    <section class="panel direct-late-panel" id="direct-late-panel" data-requires="super_admin">
                        <div class="panel-heading">
                            <div class="heading-title-group">
                                <div class="heading-badge-wrap">
                                    <h2>Izin terlambat langsung</h2>
                                    <span class="super-admin-badge">Super Admin</span>
                                </div>
                                <p>Tetapkan izin keterlambatan tanpa permohonan mandiri dari karyawan. Timestamp absensi tetap terjaga.</p>
                            </div>
                            <button type="button" class="secondary-button direct-toggle-btn" id="direct-late-toggle" aria-expanded="false">
                                <span>+ Buka formulir</span>
                            </button>
                        </div>
                        <form class="direct-late-form" id="direct-late-form" hidden>
                            <div class="direct-form-grid">
                                <label>Tanggal kerja
                                    <input type="date" name="work_date" required>
                                </label>
                                <label>Sesi shift
                                    <select name="shift_session_id" required>
                                        <option value="">Pilih sesi</option>
                                    </select>
                                </label>
                                <label>Target jam hadir (opsional)
                                    <input type="datetime-local" name="allowed_until">
                                </label>
                                <label>Durasi toleransi izin (menit)
                                    <input type="number" name="allowed_minutes" min="0" max="1440" placeholder="Contoh: 30">
                                </label>
                                <label class="field-full">Alasan penetapan izin
                                    <textarea name="reason" rows="2" maxlength="2000" placeholder="Tuliskan alasan izin langsung..." required></textarea>
                                </label>
                                <label class="field-full">Lampiran bukti (opsional: JPG, PNG, PDF)
                                    <input type="file" name="evidence" accept="image/jpeg,image/png,application/pdf">
                                </label>
                            </div>
                            <div class="direct-form-actions">
                                <button type="button" class="secondary-button" id="direct-late-cancel">Tutup</button>
                                <button type="submit" class="primary-button">Tetapkan izin</button>
                            </div>
                        </form>
                    </section>
                    <div class="workflow-grid">
                        <section class="panel workflow-queue-panel">
                            <div class="panel-heading">
                                <div>
                                    <div class="queue-title-wrap">
                                        <h2>Izin Terlambat</h2>
                                        <span class="queue-count-badge" id="late-permission-count-badge">0</span>
                                    </div>
                                    <p id="late-permission-count">Memuat…</p>
                                </div>
                            </div>
                            <div id="late-permission-queue" class="workflow-queue-list" aria-live="polite">
                                <p class="list-empty">Memuat data…</p>
                            </div>
                        </section>
                        <section class="panel workflow-queue-panel">
                            <div class="panel-heading">
                                <div>
                                    <div class="queue-title-wrap">
                                        <h2>Koreksi Absensi</h2>
                                        <span class="queue-count-badge" id="correction-count-badge">0</span>
                                    </div>
                                    <p id="correction-count">Memuat…</p>
                                </div>
                            </div>
                            <div id="correction-queue" class="workflow-queue-list" aria-live="polite">
                                <p class="list-empty">Memuat data…</p>
                            </div>
                        </section>
                    </div>
                </section>
                <section class="view" data-view-panel="employees">
                    <div class="page-heading">
                        <div><h1>Karyawan</h1><span>Data pegawai, akun app, dan status aktif.</span></div>
                        <div class="page-heading-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <a href="/admin/api/employees/import-template" class="secondary-button" download="template_import_karyawan.csv" data-requires="admin">Unduh Template CSV</a>
                            <button type="button" class="secondary-button" id="employee-import-btn" data-requires="admin">Import CSV</button>
                            <button type="button" class="primary-button" id="employee-add" data-requires="admin">+ Tambah karyawan</button>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="toolbar">
                            <label class="sr-only" for="employee-q">Cari nama atau kode</label>
                            <input id="employee-q" type="search" placeholder="Cari nama atau kode" autocomplete="off">
                            <label class="sr-only" for="employee-department">Departemen</label>
                            <select id="employee-department"><option value="">Semua departemen</option></select>
                            <label class="sr-only" for="employee-status">Status</label>
                            <select id="employee-status"><option value="active">Aktif</option><option value="inactive">Nonaktif</option><option value="">Semua status</option></select>
                        </div>
                        <div id="employee-table" aria-live="polite"></div>
                    </div>
                </section>
                <section class="view placeholder-view" data-view-panel="reports"><h1>Laporan</h1><p>Rekap kehadiran, lembur, tunjangan, dan periode.</p><div class="empty-state"><strong>Belum tersedia</strong><span>Laporan dan ekspor akan ditambahkan setelah perhitungan kehadiran selesai.</span></div></section>
                <section class="view" data-view-panel="leaves" data-requires="admin">
                    <div class="page-heading"><div><h1>Cuti dan day off</h1><span>Pengajuan memakai satu hari kerja berdasarkan tanggal awal siklus, termasuk shift split dan shift malam.</span></div><button type="button" class="primary-button" id="leave-direct-add">Catat cuti</button></div>
                    <div class="panel leave-year-panel">
                        <div class="panel-heading"><div><h2>Saldo tahun berjalan</h2><p id="leave-opening-state">Memuat status saldo…</p></div><button type="button" class="secondary-button" id="leave-opening-confirm">Konfirmasi saldo awal</button></div>
                        <form id="leave-settings-form" class="inline-settings-form">
                            <label>Tahun saldo<input type="number" name="year" min="2000" max="2100" required></label>
                            <label>Cuti reguler per tahun<input type="number" name="regular_quota" min="0" max="60" required></label>
                            <label>Day off per tahun<input type="number" name="day_off_quota" min="0" max="60" required></label>
                            <label>Alasan perubahan<input name="reason" maxlength="1000" required></label>
                            <button type="submit" class="secondary-button">Simpan kuota</button>
                            <p class="form-error" id="leave-settings-error" role="alert" hidden></p>
                        </form>
                    </div>
                    <div class="panel"><div class="panel-heading"><div><h2>Sisa kuota per karyawan</h2><p id="leave-balance-count">Memuat saldo…</p></div></div><div id="leave-balance-table" aria-live="polite"></div></div>
                    <div class="panel">
                        <div class="panel-heading"><div><h2>Pengajuan cuti</h2><p id="leave-list-count"></p></div><label class="sr-only" for="leave-status-filter">Filter status pengajuan</label><select id="leave-status-filter"><option value="">Semua status</option><option value="PENDING">Menunggu</option><option value="APPROVED">Disetujui</option><option value="REJECTED">Ditolak</option><option value="CANCELLED">Dibatalkan</option></select></div>
                        <div id="leave-table" aria-live="polite"></div>
                    </div>
                </section>
                <section class="view" data-view-panel="allowances" data-requires="admin">
                    <div class="page-heading"><div><h1>Rekap tunjangan</h1><span>Satu tunjangan makan dan transport per siklus dengan kehadiran tervalidasi.</span></div></div>
                    <form id="allowance-filters" class="attendance-filters">
                        <label>Dari<input type="date" name="from" required></label><label>Sampai<input type="date" name="to" required></label><button type="submit" class="secondary-button">Tampilkan</button>
                    </form>
                    <p class="form-error" id="allowance-error" role="alert" hidden></p>
                    <div class="metric-grid attendance-metrics"><article class="metric-card"><span>Siklus memenuhi syarat</span><strong id="allowance-eligible">—</strong></article><article class="metric-card"><span>Menunggu verifikasi</span><strong id="allowance-pending">—</strong></article><article class="metric-card"><span>Butuh konfigurasi level/tarif</span><strong id="allowance-unconfigured">—</strong></article><article class="metric-card"><span>Total makan + transport</span><strong id="allowance-total">—</strong></article></div>
                    <div class="panel"><div class="panel-heading"><div><h2>Per siklus kerja</h2><p id="allowance-count"></p></div></div><div id="allowance-table" aria-live="polite"></div></div>
                </section>
                <section class="view" data-view-panel="settings">
                    <div class="page-heading"><div><h1>Pengaturan</h1><span>Data dasar yang dipakai jadwal dan absensi.</span></div></div>
                    <div class="settings-view-layout">
                        <aside class="settings-nav-aside" aria-label="Kategori pengaturan">
                            <div class="tabs settings-vertical-tabs" role="tablist" aria-orientation="vertical" aria-label="Bagian pengaturan">
                                <button type="button" role="tab" aria-selected="true" data-settings-tab="general"><svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg><span>Umum</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="templates" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg><span>Template shift</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="policies" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-shield-check"/></svg><span>Aturan kehadiran</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="departments" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-building"/></svg><span>Departemen</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="positions" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-briefcase"/></svg><span>Jabatan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="locations" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"/></svg><span>Lokasi kerja</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="levels" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-briefcase"/></svg><span>Level karyawan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="allowances" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg><span>Tarif tunjangan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="allowance-overrides" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg><span>Pengecualian individu</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="users" data-requires="super_admin"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg><span>Akun admin</span></button>
                            </div>
                        </aside>
                        <div class="panel settings-content-panel">
                            <div class="panel-heading"><div><h2 id="settings-title"></h2><p id="settings-hint"></p></div><button type="button" class="secondary-button" id="settings-add">+ Tambah</button></div>
                            <form id="site-settings-form" class="site-settings-form" hidden>
                                <p class="form-error" id="site-settings-error" role="alert" hidden></p>
                                <div class="site-settings-layout">
                                    <div class="site-settings-fields">
                                        <section class="site-settings-section">
                                            <h3>Identitas website</h3>
                                            <label>Nama website<input name="site_name" maxlength="120" required><small class="field-error" id="site-site-name-error" hidden></small></label>
                                            <label>Logo untuk halaman login dan sidebar<input id="site-logo-file" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><small class="field-hint">PNG, JPG, atau WebP · Maksimal 2 MB</small></label>
                                            <div class="site-asset-preview"><img id="site-logo-preview" alt="Pratinjau logo" hidden><span id="site-logo-empty">Belum ada logo</span><label class="check-field" id="site-logo-remove-wrap" hidden><input name="remove_logo" type="checkbox" value="1"><span>Hapus logo tersimpan</span></label><small class="field-error" id="site-logo-error" hidden></small></div>
                                            <label>Favicon tab browser<input id="site-favicon-file" name="favicon" type="file" accept="image/png,.ico"><small class="field-hint">PNG atau ICO · Maksimal 2 MB</small></label>
                                            <div class="site-asset-preview favicon-preview"><img id="site-favicon-preview" alt="Pratinjau favicon" hidden><span id="site-favicon-empty">Belum ada favicon</span><label class="check-field" id="site-favicon-remove-wrap" hidden><input name="remove_favicon" type="checkbox" value="1"><span>Hapus favicon tersimpan</span></label><small class="field-error" id="site-favicon-error" hidden></small></div>
                                        </section>
                                        <section class="site-settings-section">
                                            <h3>Kontak website</h3>
                                            <label>Email<input name="contact_email" type="email" maxlength="255" autocomplete="email"><small class="field-error" id="site-contact-email-error" hidden></small></label>
                                            <label>Telepon / WhatsApp<input name="contact_phone" type="tel" maxlength="40" autocomplete="tel"><small class="field-error" id="site-contact-phone-error" hidden></small></label>
                                            <label>Alamat<textarea name="contact_address" rows="3" maxlength="500" autocomplete="street-address"></textarea><small class="field-error" id="site-contact-address-error" hidden></small></label>
                                            <p class="field-hint">Kontak yang diisi akan tampil di halaman login.</p>
                                        </section>
                                    </div>
                                    <aside class="site-preview" aria-live="polite" aria-label="Pratinjau halaman login">
                                        <h3>Pratinjau login</h3>
                                        <div class="site-preview-card">
                                            <img id="site-preview-logo" alt="" hidden>
                                            <span id="site-preview-globe" class="auth-mark" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><ellipse cx="12" cy="12" rx="4" ry="9"></ellipse><path d="M3.5 9h17M3.5 15h17"></path></svg>
                                            </span>
                                            <strong id="site-preview-name">Deresort Attendance</strong>
                                            <div id="site-preview-contact" class="site-preview-contact"></div>
                                        </div>
                                    </aside>
                                </div>
                                <div class="site-settings-actions"><button type="submit" class="primary-button" id="site-settings-save">Simpan perubahan</button></div>
                            </form>
                            <div id="settings-table" aria-live="polite"></div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <dialog class="app-dialog schedule-editor-dialog" id="schedule-modal" aria-labelledby="schedule-modal-title">
        <form method="dialog" id="schedule-form">
            <div class="modal-heading"><div><h2 id="schedule-modal-title">Buat jadwal</h2><p>Jadwal baru tersimpan sebagai draft. Karyawan melihatnya setelah diterbitkan.</p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body">
                <div class="schedule-modal-top-grid">
                    <label>Karyawan<select id="schedule-employee" required></select></label>
                    <label>Tanggal kerja<input type="date" id="schedule-date" required></label>
                    <label>Jenis jadwal<select id="schedule-mode"><option value="template">Template shift</option><option value="custom">Split khusus</option></select></label>
                    <label id="schedule-template-wrap">Template shift<select id="schedule-template" required></select></label>
                </div>
                <div id="schedule-custom-wrap" class="schedule-custom-wrap" hidden>
                    <label>Nama jadwal<input id="schedule-name" maxlength="100" value="Shift khusus"></label>
                    <div class="schedule-sessions-section">
                        <div class="schedule-sessions-heading">
                            <strong>Daftar Sesi Shift Khusus</strong>
                            <span class="field-hint">Maksimal 4 sesi per siklus kerja. Total jam kerja maksimal 12 jam, rentang maksimal 13 jam.</span>
                        </div>
                        <div id="schedule-session-rows" class="schedule-session-cards"></div>
                        <button type="button" class="secondary-button add-session-button" id="schedule-add-session"><span style="font-size: 16px; font-weight: bold; line-height: 1;">+</span> Tambah sesi</button>
                    </div>
                    <label id="schedule-reason-wrap" hidden>Alasan perubahan<input id="schedule-reason" maxlength="500" minlength="3" placeholder="Contoh: Penyesuaian jam operasional shift"></label>
                </div>
                <p class="form-error" id="schedule-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button">Simpan draft</button></div>
        </form>
    </dialog>
    <dialog class="app-dialog schedule-swap-dialog" id="schedule-swap-modal" aria-labelledby="schedule-swap-title">
        <form id="schedule-swap-form">
            <div class="modal-heading">
                <div>
                    <h2 id="schedule-swap-title">Tukar siklus jadwal</h2>
                    <p id="schedule-swap-subtitle">Tukar jadwal karyawan atau susun rantai pertukaran shift.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button>
            </div>
            <div class="modal-body">
                <div class="schedule-swap-main-card">
                    <label>Jadwal yang ingin ditukar
                        <select id="schedule-swap-source-assignment" required></select>
                    </label>
                    <label>Karyawan pengganti
                        <select id="schedule-swap-target-employee" required></select>
                    </label>
                </div>

                <div id="schedule-swap-options-section" class="schedule-swap-options-section" hidden>
                    <div class="schedule-swap-section-title">
                        <strong id="schedule-swap-options-title">Pilihan pertukaran jadwal</strong>
                        <p class="field-hint" id="schedule-swap-options-hint">Pilih perlakuan untuk jadwal milik karyawan pengganti.</p>
                    </div>

                    <div class="schedule-swap-radio-group">
                        <label class="schedule-swap-radio-label">
                            <input type="radio" name="swap_mode" value="direct" id="swap-mode-direct" checked>
                            <div>
                                <span class="radio-title" id="swap-mode-direct-title">Tukar langsung dengan karyawan pengaju</span>
                                <small class="radio-desc">Jadwal karyawan pengganti langsung ditukarkan kepada karyawan yang mengajukan perubahan jadwal.</small>
                            </div>
                        </label>

                        <div id="schedule-swap-direct-config" class="schedule-swap-subfield">
                            <label>Pilih jadwal karyawan pengganti untuk ditukar balik
                                <select id="schedule-swap-target-schedule"></select>
                            </label>
                            <p class="field-hint" id="schedule-swap-direct-target-hint"></p>
                        </div>

                        <label class="schedule-swap-radio-label">
                            <input type="radio" name="swap_mode" value="chain" id="swap-mode-chain">
                            <div>
                                <span class="radio-title">Digantikan karyawan lain lagi</span>
                                <small class="radio-desc">Jadwal karyawan pengganti dialihkan ke karyawan ketiga dalam rantai pertukaran.</small>
                            </div>
                        </label>

                        <div id="schedule-swap-chain-config" class="schedule-swap-subfield" hidden>
                            <label>Pilih jadwal karyawan pengganti yang dialihkan
                                <select id="schedule-swap-chain-schedule"></select>
                            </label>
                            <label>Karyawan pengganti berikutnya
                                <select id="schedule-swap-chain-target"></select>
                            </label>
                        </div>

                        <label class="schedule-swap-radio-label">
                            <input type="radio" name="swap_mode" value="none" id="swap-mode-none">
                            <div>
                                <span class="radio-title">Tanpa tukar jadwal balik (Pelimpahan shift)</span>
                                <small class="radio-desc">Hanya jadwal awal yang dialihkan; karyawan pengaju tidak menerima shift pengganti (libur/cuti).</small>
                            </div>
                        </label>
                    </div>
                </div>

                <div id="schedule-swap-extra-section" class="schedule-swap-extra-section">
                    <div id="schedule-swap-extra-moves" class="form-fields"></div>
                    <button type="button" class="text-button" id="schedule-swap-add">+ Tambah jadwal lanjutan dalam rantai</button>
                </div>

                <label class="check-field"><input type="checkbox" id="schedule-swap-cross-unit"><span>Konfirmasi jika pratinjau menunjukkan perpindahan lintas departemen</span></label>
                <label>Alasan perubahan<textarea id="schedule-swap-reason" rows="3" maxlength="1000" required placeholder="Contoh: Tukar shift atas persetujuan bersama"></textarea></label>
                <div id="schedule-swap-result" class="form-fields" aria-live="polite"></div>
                <p class="form-error" id="schedule-swap-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="button" class="secondary-button" id="schedule-swap-preview">Pratinjau</button>
                <button type="submit" class="primary-button" id="schedule-swap-save" disabled>Terapkan tukar</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog employee-import-dialog" id="employee-import-modal" aria-labelledby="employee-import-modal-title">
        <form id="employee-import-form">
            <div class="modal-heading">
                <div>
                    <h2 id="employee-import-modal-title">Import Karyawan Massal (CSV)</h2>
                    <p id="employee-import-modal-hint">Unggah file CSV untuk mendaftarkan banyak data karyawan sekaligus.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button>
            </div>
            <div class="modal-body">
                <div class="form-fields">
                    <div style="background: var(--surface-soft, #f8fafc); border: 1px dashed var(--line, #cbd5e1); border-radius: 10px; padding: 16px; text-align: center;">
                        <input type="file" id="employee-import-file" accept=".csv,text/csv,text/plain" style="margin-bottom: 8px; width: auto; max-width: 100%;">
                        <div style="font-size: 12px; color: var(--muted, #64748b); line-height: 1.5;">
                            Mendukung pemisah koma (<code>,</code>) atau titik koma (<code>;</code>). Format UTF-8 / Excel CSV (maks. 5MB).<br>
                            Gunakan format kolom resmi: <a href="/admin/api/employees/import-template" download="template_import_karyawan.csv" style="color: var(--primary, #1e40af); text-decoration: underline; font-weight: 600;">Unduh Template CSV</a>
                        </div>
                    </div>

                    <div id="employee-import-preview-container" hidden>
                        <div id="employee-import-stats" style="margin: 4px 0; font-size: 13px; font-weight: 600;"></div>
                        <div style="max-height: 280px; overflow: auto; border: 1px solid var(--line, #e2e8f0); border-radius: 8px;">
                            <table class="data-table" style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="padding: 8px; text-align: left;">Baris</th>
                                        <th style="padding: 8px; text-align: left;">Status</th>
                                        <th style="padding: 8px; text-align: left;">Kode</th>
                                        <th style="padding: 8px; text-align: left;">Nama</th>
                                        <th style="padding: 8px; text-align: left;">Departemen</th>
                                        <th style="padding: 8px; text-align: left;">Jabatan</th>
                                        <th style="padding: 8px; text-align: left;">Status Kerja</th>
                                        <th style="padding: 8px; text-align: left;">Keterangan / Error</th>
                                    </tr>
                                </thead>
                                <tbody id="employee-import-table-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <p class="form-error" id="employee-import-error" role="alert" hidden></p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="submit" class="primary-button" id="employee-import-submit" disabled>Simpan & Impor Karyawan</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="form-modal" aria-labelledby="form-modal-title">
        <form method="dialog" id="form-modal-form" novalidate>
            <div class="modal-heading"><div><h2 id="form-modal-title"></h2><p id="form-modal-hint"></p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body"><div id="form-modal-fields" class="form-fields"></div><p class="form-error" id="form-modal-error" role="alert" hidden></p></div>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button" id="form-modal-submit">Simpan</button></div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="workflow-modal" aria-labelledby="workflow-modal-title"><form id="workflow-modal-form"><div class="modal-heading"><div><h2 id="workflow-modal-title"></h2><p id="workflow-modal-hint"></p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div><div class="modal-body"><div id="workflow-modal-fields" class="form-fields"></div><p class="form-error" id="workflow-modal-error" role="alert" hidden></p></div><div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button" id="workflow-modal-submit">Simpan</button></div></form></dialog>
    <dialog class="app-dialog confirm-dialog" id="confirm-modal" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message">
        <form method="dialog">
            <div class="modal-heading"><div><h2 id="confirm-modal-title">Konfirmasi</h2></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body"><p id="confirm-modal-message"></p></div>
            <div class="modal-actions"><button type="submit" class="secondary-button" value="cancel" autofocus>Batal</button><button type="submit" class="primary-button" id="confirm-modal-submit" value="confirm">Lanjutkan</button></div>
        </form>
    </dialog>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
