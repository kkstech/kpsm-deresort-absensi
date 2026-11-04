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
        <symbol id="i-check-circle" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></symbol>
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
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
        <symbol id="i-menu" viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></symbol>
        <symbol id="i-coins" viewBox="0 0 24 24"><ellipse cx="12" cy="7" rx="9" ry="3"/><path d="M3 7v6c0 1.66 4.03 3 9 3s9-1.34 9-3V7M3 13v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6"/></symbol>
        <symbol id="i-umbrella" viewBox="0 0 24 24"><path d="M23 12a11.05 11.05 0 00-22 0zm-5 7a3 3 0 01-6 0v-7"/></symbol>
        <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
        <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></symbol>
        <symbol id="i-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></symbol>
        <symbol id="i-file-text" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
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
                <button type="button" class="nav-item" data-view="leaves" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-umbrella"/></svg>Cuti</button>
                <button type="button" class="nav-item" data-view="allowances" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-coins"/></svg>Tunjangan</button>
                <button type="button" class="nav-item" data-view="reports"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg>Laporan</button>
            </nav>
            <div class="sidebar-bottom">
                <button type="button" class="nav-item" data-view="settings" data-requires="web_admin"><svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg>Pengaturan</button>
                <div class="operator">
                    @if ($me->avatar_url)
                        <img src="{{ $me->avatar_url }}" alt="{{ $me->name }}" class="avatar" style="object-fit: cover;">
                    @else
                        <span class="avatar">{{ \Illuminate\Support\Str::of($me->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
                    @endif
                    <span><strong>{{ $me->name }}</strong><small>{{ ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'supervisor' => 'Supervisor'][$me->role] ?? $me->role }}</small></span>
                </div>
                <form method="post" action="{{ route('logout') }}" class="logout-form">
                    @csrf
                    <button type="submit" class="nav-item logout-button">
                        <svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <button type="button" class="icon-button menu-button" id="menu-button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="sidebar"><svg class="icon" aria-hidden="true"><use href="#i-menu"/></svg></button>
                <div class="search-wrap">
                    <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                    <label class="sr-only" for="global-search">Cari karyawan</label>
                    <input id="global-search" type="search" list="employee-list" placeholder="Cari karyawan, lihat jadwalnya" autocomplete="off">
                    <datalist id="employee-list"></datalist>
                    <kbd>⌘ K</kbd>
                </div>
                <div class="top-actions">
                    <span class="server-state"><i></i>Server lokal aktif</span>
                    <button type="button" class="icon-button notification-button" id="notification-button" aria-label="Buka notifikasi"><svg class="icon" aria-hidden="true"><use href="#i-bell"/></svg><b id="notification-unread-count" hidden></b></button>
                </div>
            </header>

            <main id="main-content" tabindex="-1">
                <section class="view active" data-view-panel="dashboard">
                    <div class="dashboard-hero-banner">
                        <div class="hero-main">
                            <h1 class="hero-greeting" id="dashboard-greeting">Selamat Datang, {{ $me->name }}</h1>
                            <p class="hero-subtitle" id="dashboard-finding">Ringkasan kehadiran dan operasional shift resort hari ini.</p>
                        </div>
                        <div class="hero-actions">
                            <span class="hero-date-badge" id="dashboard-today-date">
                                <svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                                <span id="dashboard-date-str">Hari Ini</span>
                            </span>
                            <button type="button" class="secondary-button hero-btn" data-view-link="schedule">
                                <svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                                <span>Jadwal Shift</span>
                            </button>
                            <button type="button" class="primary-button hero-btn" data-view-link="attendance">
                                <span>Monitoring Absensi</span>
                            </button>
                        </div>
                    </div>

                    <div class="dashboard-filter-card panel">
                        <div class="preset-chips-wrap">
                            <span class="preset-label">Periode:</span>
                            <div class="preset-buttons" id="dashboard-preset-chips">
                                <button type="button" class="preset-chip active" data-preset="today">Hari Ini</button>
                                <button type="button" class="preset-chip" data-preset="yesterday">Kemarin</button>
                                <button type="button" class="preset-chip" data-preset="7d">7 Hari Terakhir</button>
                                <button type="button" class="preset-chip" data-preset="month">Bulan Ini</button>
                            </div>
                        </div>
                        <form class="dashboard-filters-form" id="dashboard-filters">
                            <div class="filter-inputs-row">
                                <label>Dari<input type="date" name="from" required></label>
                                <label>Sampai<input type="date" name="to" required></label>
                                <label>Departemen<select name="department_id"><option value="">Semua departemen</option></select></label>
                                <label>Karyawan<select name="employee_id"><option value="">Semua karyawan</option></select></label>
                                <button type="submit" class="secondary-button filter-submit-btn">Terapkan</button>
                            </div>
                        </form>
                    </div>
                    <p class="form-error" id="dashboard-error" role="alert" hidden></p>

                    <section class="dashboard-metrics-grid" aria-label="Ringkasan kehadiran" aria-live="polite">
                        <!-- Card 1: Tingkat Kehadiran -->
                        <div class="dashboard-metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Tingkat Kehadiran</span>
                                <span class="metric-icon-badge"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></span>
                            </div>
                            <div class="metric-main-value">
                                <strong id="metric-attendance-rate">0%</strong>
                                <span class="metric-tag" id="metric-rate-tag">Kehadiran</span>
                            </div>
                            <div class="metric-progress-wrap">
                                <div class="metric-progress-bar"><div class="metric-progress-fill" id="metric-rate-fill" style="width: 0%;"></div></div>
                            </div>
                            <small class="metric-subtext" id="metric-sessions-ratio">0 dari 0 sesi check-in</small>
                        </div>

                        <!-- Card 2: Karyawan Bertugas -->
                        <div class="dashboard-metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Karyawan Bertugas</span>
                                <span class="metric-icon-badge"><svg class="icon" aria-hidden="true"><use href="#i-people"/></svg></span>
                            </div>
                            <div class="metric-main-value">
                                <strong id="metric-employees">0</strong>
                                <span class="metric-tag">Orang</span>
                            </div>
                            <div class="metric-extra-info">
                                <span id="metric-checkins">0 sesi check-in</span>
                            </div>
                            <small class="metric-subtext" id="metric-sessions">0 sesi terjadwal</small>
                        </div>

                        <!-- Card 3: Perlu Review & Tindakan -->
                        <div class="dashboard-metric-card" id="metric-card-review">
                            <div class="metric-header">
                                <span class="metric-title">Perlu Perhatian</span>
                                <span class="metric-icon-badge alert"><svg class="icon" aria-hidden="true"><use href="#i-alert"/></svg></span>
                            </div>
                            <div class="metric-main-value">
                                <strong id="metric-review">0</strong>
                                <span class="metric-tag alert" id="metric-review-tag">Tinjauan</span>
                            </div>
                            <div class="metric-extra-info">
                                <span id="metric-pending-leaves">0 pengajuan cuti</span>
                            </div>
                            <small class="metric-subtext">Sesi absensi butuh tindakan verifikasi</small>
                        </div>

                        <!-- Card 4: Total Jam Kerja -->
                        <div class="dashboard-metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Total Jam Produktif</span>
                                <span class="metric-icon-badge"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span>
                            </div>
                            <div class="metric-main-value">
                                <strong id="metric-minutes">0j 0m</strong>
                            </div>
                            <div class="metric-extra-info">
                                <span>Jam kerja terverifikasi</span>
                            </div>
                            <small class="metric-subtext">Berdasarkan check-in valid</small>
                        </div>
                    </section>

                    <!-- Bilah Distribusi Status Kehadiran -->
                    <section class="dashboard-breakdown-card panel">
                        <div class="breakdown-header">
                            <div>
                                <h3>Distribusi Status Kehadiran</h3>
                                <p>Rincian status seluruh shift operasional pada rentang yang dipilih.</p>
                            </div>
                            <span class="breakdown-total" id="breakdown-total-sessions">0 Total Sesi</span>
                        </div>
                        <div class="multi-status-bar" id="dashboard-status-bar">
                            <div class="bar-segment bar-ontime" style="width: 0%;" title="Tepat Waktu"></div>
                            <div class="bar-segment bar-inprogress" style="width: 0%;" title="Sedang Bekerja"></div>
                            <div class="bar-segment bar-late" style="width: 0%;" title="Terlambat"></div>
                            <div class="bar-segment bar-review" style="width: 0%;" title="Perlu Review"></div>
                            <div class="bar-segment bar-absent" style="width: 0%;" title="Belum Masuk / Absen"></div>
                        </div>
                        <div class="distribution-legend">
                            <div class="legend-item"><span class="legend-dot ontime"></span><span>Tepat Waktu: <b id="leg-ontime">0</b></span></div>
                            <div class="legend-item"><span class="legend-dot inprogress"></span><span>Sedang Bekerja: <b id="leg-inprogress">0</b></span></div>
                            <div class="legend-item"><span class="legend-dot late"></span><span>Terlambat: <b id="leg-late">0</b></span></div>
                            <div class="legend-item"><span class="legend-dot review"></span><span>Perlu Review: <b id="leg-review">0</b></span></div>
                            <div class="legend-item"><span class="legend-dot absent"></span><span>Belum Masuk: <b id="leg-absent">0</b></span></div>
                        </div>
                    </section>

                    <!-- Modular Grid: Aktivitas (Kiri) + Review & Pintasan (Kanan) -->
                    <section class="dashboard-modular-grid">
                        <div class="panel dashboard-activity-panel">
                            <div class="panel-heading">
                                <div>
                                    <h2>Aktivitas Absensi Terkini</h2>
                                    <p>Event check-in dan check-out terbaru yang tercatat sistem.</p>
                                </div>
                                <button type="button" class="text-button" data-view-link="attendance">Buka monitoring</button>
                            </div>
                            <div id="dashboard-activity" aria-live="polite"><p class="list-empty">Memuat data…</p></div>
                            <div id="dashboard-activity-pagination" class="table-pagination"></div>
                        </div>

                        <div class="dashboard-side-col">
                            <div class="panel dashboard-review-panel">
                                <div class="panel-heading">
                                    <div>
                                        <h3>Perlu Tindakan Segera</h3>
                                        <p>Sesi dengan kendala GPS, foto, atau keterlambatan.</p>
                                    </div>
                                    <button type="button" class="text-button" data-view-link="attendance">Tinjau semua</button>
                                </div>
                                <div id="dashboard-review-box" class="review-box-content">
                                    <p class="list-empty">Memeriksa data review…</p>
                                </div>
                            </div>

                            <div class="panel dashboard-shortcuts-panel">
                                <div class="panel-heading">
                                    <h3>Akses Cepat Pengelolaan</h3>
                                </div>
                                <div class="shortcuts-grid">
                                    <button type="button" class="shortcut-tile" data-view-link="schedule">
                                        <span class="shortcut-icon">
                                            <svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                                        </span>
                                        <div class="shortcut-text">
                                            <strong>Jadwal &amp; Shift</strong>
                                            <small>Atur jadwal mingguan &amp; sesi</small>
                                        </div>
                                    </button>
                                    <button type="button" class="shortcut-tile" data-view-link="employees">
                                        <span class="shortcut-icon">
                                            <svg class="icon" aria-hidden="true"><use href="#i-people"/></svg>
                                        </span>
                                        <div class="shortcut-text">
                                            <strong>Data Karyawan</strong>
                                            <small>Kelola NIK, foto, &amp; level</small>
                                        </div>
                                    </button>
                                    <button type="button" class="shortcut-tile" data-view-link="allowances">
                                        <span class="shortcut-icon">
                                            <svg class="icon" aria-hidden="true"><use href="#i-coins"/></svg>
                                        </span>
                                        <div class="shortcut-text">
                                            <strong>Rekap Tunjangan</strong>
                                            <small>Uang makan &amp; transport</small>
                                        </div>
                                    </button>
                                    <button type="button" class="shortcut-tile" data-view-link="leaves">
                                        <span class="shortcut-icon">
                                            <svg class="icon" aria-hidden="true"><use href="#i-umbrella"/></svg>
                                        </span>
                                        <div class="shortcut-text">
                                            <strong>Manajemen Cuti</strong>
                                            <small>Saldo &amp; permohonan cuti</small>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </section>

                <section class="view schedule-view" data-view-panel="schedule">
                    <div class="page-heading">
                        <div><p>Perencanaan shift</p><h1>Jadwal &amp; Shift</h1><span>Pilih tampilan mingguan atau bulanan. Pilih karyawan untuk melihat jadwalnya saja.</span></div>
                        <div class="heading-actions schedule-heading-grid" data-requires="admin">
                            <button type="button" class="primary-button" data-open-modal="schedule-modal">
                                <svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                                <span>Buat Jadwal</span>
                            </button>
                            <button type="button" class="secondary-button" id="schedule-bulk-btn">
                                <svg class="icon" aria-hidden="true"><use href="#i-people"/></svg>
                                <span>Penugasan Massal</span>
                            </button>
                            <button type="button" class="secondary-button" id="schedule-publish">
                                <svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>
                                <span>Terbitkan Draft</span>
                            </button>
                            <button type="button" class="secondary-button" id="schedule-copy">
                                <svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>
                                <span>Salin Minggu Depan</span>
                            </button>
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
                                        <button type="button" class="icon-button calendar-nav" id="calendar-prev" aria-label="Minggu sebelumnya"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"/></svg></button>
                                        <button type="button" class="icon-button calendar-nav" id="calendar-next" aria-label="Minggu berikutnya"><svg class="icon" aria-hidden="true"><use href="#i-chevron-right"/></svg></button>
                                    </div>
                                </div>
                            </div>
                            <div id="calendar-body"></div>
                            <div id="schedule-pagination" class="table-pagination"></div>
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
                    <div class="page-heading">
                        <div>
                            <p data-site-name>{{ $siteSettings->site_name }}</p>
                            <h1>Monitoring Absensi</h1>
                            <span>Catatan absensi per sesi kerja, log check-in/out, toleransi terlambat, dan review.</span>
                        </div>
                    </div>

                    <div class="monitoring-filter-card panel" style="padding: 16px 20px; margin-bottom: 20px;">
                        <form class="monitoring-filter-form" id="monitoring-filters">
                            <div class="monitoring-presets-bar">
                                <span class="allowance-preset-label">Periode Cepat:</span>
                                <div class="monitoring-preset-chips">
                                    <button type="button" class="preset-chip active" data-monitoring-preset="today">Hari Ini</button>
                                    <button type="button" class="preset-chip" data-monitoring-preset="yesterday">Kemarin</button>
                                    <button type="button" class="preset-chip" data-monitoring-preset="week">7 Hari Terakhir</button>
                                    <button type="button" class="preset-chip" data-monitoring-preset="month">Bulan Ini</button>
                                </div>
                            </div>
                            <div class="monitoring-inputs-grid">
                                <label>
                                    <span>Dari Tanggal</span>
                                    <input type="date" name="from" id="monitoring-from" required>
                                </label>
                                <label>
                                    <span>Sampai Tanggal</span>
                                    <input type="date" name="to" id="monitoring-to" required>
                                </label>
                                <label>
                                    <span>Departemen</span>
                                    <select name="department_id" id="monitoring-dept">
                                        <option value="">Semua departemen</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Karyawan</span>
                                    <select name="employee_id" id="monitoring-employee">
                                        <option value="">Semua karyawan</option>
                                    </select>
                                </label>
                                <label style="flex: 1 1 200px;">
                                    <span>Cari Nama / NIK</span>
                                    <input type="search" name="q" id="monitoring-q" placeholder="Ketik nama atau kode…">
                                </label>
                                <div class="monitoring-action-btn">
                                    <button type="submit" class="primary-button" style="height: 42px; min-height: 42px; padding: 0 20px;">Terapkan Filter</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Stats Counter Strip -->
                    <div class="monitoring-stats-strip" id="monitoring-stats" style="margin-bottom: 20px;">
                        <button type="button" class="monitoring-stat-pill" data-monitoring-state="" aria-pressed="true" aria-label="Tampilkan semua sesi absensi">
                            <span class="stat-num" id="stat-total-sessions">0</span>
                            <span class="stat-label">Total Sesi</span>
                        </button>
                        <button type="button" class="monitoring-stat-pill success" data-monitoring-state="complete" aria-pressed="false" aria-label="Filter sesi hadir atau selesai">
                            <span class="stat-num" id="stat-complete-sessions">0</span>
                            <span class="stat-label">Hadir / Selesai</span>
                        </button>
                        <button type="button" class="monitoring-stat-pill blue" data-monitoring-state="in_progress" aria-pressed="false" aria-label="Filter sesi yang sedang bekerja">
                            <span class="stat-num" id="stat-inprogress-sessions">0</span>
                            <span class="stat-label">Sedang Bekerja</span>
                        </button>
                        <button type="button" class="monitoring-stat-pill warning" data-monitoring-state="late" aria-pressed="false" aria-label="Filter sesi terlambat">
                            <span class="stat-num" id="stat-late-sessions">0</span>
                            <span class="stat-label">Terlambat</span>
                        </button>
                        <button type="button" class="monitoring-stat-pill danger" data-monitoring-state="review" aria-pressed="false" aria-label="Filter sesi yang perlu review">
                            <span class="stat-num" id="stat-review-sessions">0</span>
                            <span class="stat-label">Perlu Review</span>
                        </button>
                    </div>

                    <p class="form-error" id="monitoring-error" role="alert" hidden></p>
                    <section class="panel monitoring-panel" aria-label="Daftar absensi">
                        <div class="panel-heading">
                            <div>
                                <h2>Absensi per sesi</h2>
                                <p id="monitoring-count" aria-live="polite">Memuat data…</p>
                            </div>
                        </div>
                        <div id="monitoring-table" aria-live="polite">
                            <p class="list-empty">Memuat data…</p>
                        </div>
                        <div id="monitoring-pagination" class="table-pagination"></div>
                    </section>
                </section>
                <section class="view workflow-view" data-view-panel="requests">
                    <div class="page-heading">
                        <div>
                            <p data-site-name>{{ $siteSettings->site_name }}</p>
                            <h1>Persetujuan</h1>
                            <span>Permintaan izin, koreksi absensi, lembur, dan tukar shift menunggu keputusan.</span>
                        </div>
                        <div class="page-heading-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="primary-button" id="open-overtime-order-btn" data-requires="admin">
                                + Terbitkan SPL Lembur
                            </button>
                            <button type="button" class="secondary-button workflow-refresh-btn" id="workflow-refresh">
                                <svg class="icon" aria-hidden="true" style="width: 16px; height: 16px;"><path d="M1 4v6h6M23 20v-6h-6M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                <span>Muat ulang</span>
                            </button>
                        </div>
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
                        <section class="panel workflow-queue-panel" data-requires="admin">
                            <div class="panel-heading">
                                <div>
                                    <div class="queue-title-wrap">
                                        <h2>Lembur &amp; SPL</h2>
                                        <span class="queue-count-badge" id="overtime-count-badge">0</span>
                                    </div>
                                    <p id="overtime-count">Memuat…</p>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <select id="overtime-filter" style="font-size: 12px; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--line); background: #fff;" aria-label="Filter status lembur">
                                        <option value="PENDING">Menunggu</option>
                                        <option value="APPROVED">Disetujui / SPL Aktif</option>
                                        <option value="all">Semua</option>
                                    </select>
                                </div>
                            </div>
                            <div class="overtime-recommendations" aria-labelledby="overtime-recommendation-heading">
                                <div class="panel-heading" style="padding: 14px 18px 8px;">
                                    <div>
                                        <h3 id="overtime-recommendation-heading" style="font-size:14px;margin:0;">Shift berakhir dalam 2 jam</h3>
                                        <p id="overtime-recommendation-count" style="margin:4px 0 0;color:var(--muted);font-size:12px;">Memuat rekomendasi…</p>
                                    </div>
                                </div>
                                <div id="overtime-recommendation-list" class="workflow-queue-list" aria-live="polite">
                                    <p class="list-empty">Memuat rekomendasi…</p>
                                </div>
                            </div>
                            <div id="overtime-queue" class="workflow-queue-list" aria-live="polite">
                                <p class="list-empty">Memuat data…</p>
                            </div>
                        </section>
                        <section class="panel workflow-queue-panel" data-requires="admin">
                            <div class="panel-heading">
                                <div>
                                    <div class="queue-title-wrap">
                                        <h2>Tukar Shift</h2>
                                        <span class="queue-count-badge" id="shift-exchange-count-badge">0</span>
                                    </div>
                                    <p id="shift-exchange-count">Memuat pengajuan…</p>
                                </div>
                            </div>
                            <div id="shift-exchange-queue" class="workflow-queue-list" aria-live="polite">
                                <p class="list-empty">Memuat pengajuan tukar shift…</p>
                            </div>
                        </section>
                    </div>
                </section>
                <section class="view" data-view-panel="employees">
                    <div class="page-heading">
                        <div><h1>Karyawan</h1><span>Data pegawai, akun app, dan status aktif.</span></div>
                        <div class="page-heading-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="secondary-button" id="employee-template-btn" data-requires="admin">Unduh Template CSV</button>
                            <button type="button" class="secondary-button" id="employee-import-btn" data-requires="admin">Import CSV</button>
                            <button type="button" class="primary-button" id="employee-add" data-requires="admin">+ Tambah karyawan</button>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="toolbar">
                            <label class="sr-only" for="employee-q">Cari nama karyawan</label>
                            <input id="employee-q" type="search" placeholder="Cari nama karyawan..." autocomplete="off">
                            <label class="sr-only" for="employee-code">Kode / NIK</label>
                            <input id="employee-code" type="search" placeholder="Kode / NIK..." autocomplete="off" style="max-width: 150px;">
                            <label class="sr-only" for="employee-department">Departemen</label>
                            <select id="employee-department"><option value="">Semua departemen</option></select>
                            <label class="sr-only" for="employee-level">Level</label>
                            <select id="employee-level"><option value="">Semua level</option><option value="none">Belum diatur</option></select>
                            <label class="sr-only" for="employee-status">Status</label>
                            <select id="employee-status"><option value="active">Aktif</option><option value="inactive">Nonaktif</option><option value="">Semua status</option></select>
                        </div>
                        <div id="employee-table" aria-live="polite"></div>
                        <div id="employee-pagination" class="table-pagination"></div>
                    </div>
                </section>
                <section class="view report-view" data-view-panel="reports">
                    <div class="page-heading">
                        <div>
                            <p data-site-name>{{ $siteSettings->site_name }}</p>
                            <h1>Laporan &amp; Rekapitulasi</h1>
                            <span>Rekapitulasi kehadiran, jam kerja reguler, keterlambatan, lembur, dan tunjangan per periode.</span>
                        </div>
                        <div class="heading-actions report-heading-actions">
                            <button type="button" class="secondary-button" id="btn-export-excel">
                                <svg class="icon" aria-hidden="true"><use href="#i-download"/></svg>
                                <span>Unduh Excel (.xlsx)</span>
                            </button>
                            <button type="button" class="primary-button" id="btn-export-pdf">
                                <svg class="icon" aria-hidden="true"><use href="#i-file-text"/></svg>
                                <span>Unduh PDF (.pdf)</span>
                            </button>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="report-filter-panel panel">
                        <form id="report-filters" class="report-filter-form">
                            <div class="report-filter-top">
                                <div class="report-presets-group">
                                    <span class="report-filter-label">Periode Cepat:</span>
                                    <div class="report-preset-chips" id="report-preset-chips">
                                        <button type="button" class="preset-chip" data-report-preset="today">Hari Ini</button>
                                        <button type="button" class="preset-chip" data-report-preset="7d">7 Hari Terakhir</button>
                                        <button type="button" class="preset-chip active" data-report-preset="month">Bulan Ini</button>
                                        <button type="button" class="preset-chip" data-report-preset="last-month">Bulan Lalu</button>
                                    </div>
                                </div>
                                <div class="report-status-wrap">
                                    <span class="report-status-badge" id="report-status-badge">Memeriksa...</span>
                                    <span class="report-meta-text" id="report-meta-generated"></span>
                                    <button type="button" class="secondary-button" id="report-finalize-btn" data-requires="admin" disabled>Finalkan Laporan</button>
                                    <button type="button" class="secondary-button" id="report-reopen-btn" data-requires="admin" hidden>Buka Ulang Laporan</button>
                                </div>
                                <p class="report-finalize-blockers" id="report-finalize-blockers" role="alert" hidden></p>
                            </div>
                            <div class="report-filter-fields">
                                <label class="report-field report-field-date">
                                    <span>Dari Tanggal</span>
                                    <input type="date" name="from" id="report-from" required>
                                </label>
                                <label class="report-field report-field-date">
                                    <span>Sampai Tanggal</span>
                                    <input type="date" name="to" id="report-to" required>
                                </label>
                                <label class="report-field report-field-select">
                                    <span>Departemen</span>
                                    <select name="department_id" id="report-dept">
                                        <option value="">Semua departemen</option>
                                    </select>
                                </label>
                                <label class="report-field report-field-select">
                                    <span>Karyawan</span>
                                    <select name="employee_id" id="report-employee">
                                        <option value="">Semua karyawan</option>
                                    </select>
                                </label>
                                <label class="report-field report-field-search">
                                    <span>Cari Nama / NIK</span>
                                    <input type="search" name="q" id="report-search" placeholder="Ketik nama atau kode...">
                                </label>
                                <div class="report-filter-actions">
                                    <button type="submit" class="primary-button">Terapkan</button>
                                    <button type="button" class="secondary-button" id="report-reset-btn">Reset</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- KPI Cards -->
                    <div class="metric-grid report-metric-grid" id="report-kpi-grid">
                        <div class="metric-card">
                            <span>Total Karyawan</span>
                            <strong id="kpi-total-employees">0</strong>
                            <small>Karyawan dalam laporan</small>
                        </div>
                        <div class="metric-card">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span>Hari Hadir</span>
                                <span class="metric-badge success" id="kpi-attendance-rate">0% hadir</span>
                            </div>
                            <strong id="kpi-total-attended-days">0</strong>
                            <small>Akumulasi hari kerja</small>
                        </div>
                        <div class="metric-card accent">
                            <span>Jam Reguler Valid</span>
                            <strong id="kpi-total-verified-hours">0j 0m</strong>
                            <small>Total durasi kerja diakui</small>
                        </div>
                        <div class="metric-card">
                            <span>Lembur Diakui</span>
                            <strong id="kpi-total-overtime-hours">0j 0m</strong>
                            <small>Dari SPL disetujui</small>
                        </div>
                        <div class="metric-card">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span>Keterlambatan</span>
                                <span class="metric-badge warning" id="kpi-total-late-count">0x</span>
                            </div>
                            <strong id="kpi-total-late-minutes">0 m</strong>
                            <small>Total menit keterlambatan</small>
                        </div>
                        <div class="metric-card">
                            <span>Total Tunjangan</span>
                            <strong class="metric-highlight" id="kpi-total-allowance">Rp 0</strong>
                            <small id="kpi-allowance-split">Makan: Rp 0 · Trs: Rp 0</small>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <div class="report-tabs-bar" role="tablist">
                        <button type="button" class="tab-button active" data-report-tab="summary" role="tab" aria-selected="true">
                            <svg class="icon" aria-hidden="true"><use href="#i-people"/></svg>
                            <span>Rekapitulasi Karyawan</span>
                        </button>
                        <button type="button" class="tab-button" data-report-tab="details" role="tab" aria-selected="false">
                            <svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>
                            <span>Detail Kehadiran Sesi</span>
                        </button>
                        <button type="button" class="tab-button" data-report-tab="overtime" role="tab" aria-selected="false">
                            <svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>
                            <span>Rekap Lembur</span>
                        </button>
                    </div>

                    <!-- Tab 1: Summary Table -->
                    <div class="report-tab-panel active" id="tab-panel-summary">
                        <div class="panel table-card report-table-scroll">
                            <table class="data-table" id="report-summary-table">
                                <thead>
                                    <tr>
                                        <th>Karyawan</th>
                                        <th>Departemen</th>
                                        <th style="text-align: center;">Hari Hadir / Jadwal</th>
                                        <th style="text-align: center;">Sesi Hadir</th>
                                        <th style="text-align: center;">Jam Reguler</th>
                                        <th style="text-align: center;">Terlambat</th>
                                        <th style="text-align: center;">Lembur Diakui</th>
                                        <th style="text-align: center;">Cuti</th>
                                        <th style="text-align: center;">Review</th>
                                        <th style="text-align: right;">Total Tunjangan</th>
                                    </tr>
                                </thead>
                                <tbody id="report-summary-tbody">
                                    <tr><td colspan="10" style="text-align: center; padding: 24px; color: var(--text-muted);">Memuat data rekapitulasi...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Details Table -->
                    <div class="report-tab-panel" id="tab-panel-details" hidden>
                        <div class="panel table-card report-table-scroll">
                            <table class="data-table" id="report-details-table">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Karyawan</th>
                                        <th>Shift / Sesi</th>
                                        <th style="text-align: center;">Jadwal Shift</th>
                                        <th style="text-align: center;">Jam Masuk</th>
                                        <th style="text-align: center;">Jam Pulang</th>
                                        <th style="text-align: center;">Status</th>
                                        <th style="text-align: center;">Terlambat</th>
                                        <th style="text-align: center;">Jam Valid</th>
                                        <th style="text-align: center;">Lembur</th>
                                        <th style="text-align: right;">Tunjangan</th>
                                    </tr>
                                </thead>
                                <tbody id="report-details-tbody">
                                    <tr><td colspan="11" style="text-align: center; padding: 24px; color: var(--text-muted);">Pilih tab ini untuk memuat detail...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="table-pagination" id="report-details-pagination" hidden>
                            <span id="report-details-pagination-info"></span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" class="secondary-button" id="report-details-prev-btn" disabled>Sebelumnya</button>
                                <button type="button" class="secondary-button" id="report-details-next-btn" disabled>Selanjutnya</button>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Overtime Table -->
                    <div class="report-tab-panel" id="tab-panel-overtime" hidden>
                        <div class="panel table-card report-table-scroll">
                            <table class="data-table" id="report-overtime-table">
                                <thead>
                                    <tr>
                                        <th>No. SPL</th>
                                        <th>Tanggal</th>
                                        <th>Karyawan</th>
                                        <th>Departemen</th>
                                        <th>Uraian Tugas Lembur</th>
                                        <th style="text-align: center;">Jam Pelaksanaan</th>
                                        <th style="text-align: center;">Durasi Rencana</th>
                                        <th style="text-align: center;">Durasi Diakui</th>
                                        <th style="text-align: center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="report-overtime-tbody">
                                    <tr><td colspan="9" style="text-align: center; padding: 24px; color: var(--text-muted);">Memuat rekap lembur...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
                <section class="view" data-view-panel="leaves" data-requires="admin">
                    <div class="page-heading">
                        <div>
                            <h1>Cuti &amp; Deposit Off</h1>
                            <span>Pengelolaan permohonan cuti karyawan, monitoring kuota saldo tahunan, dan pencatatan cuti langsung.</span>
                        </div>
                        <div class="heading-actions">
                            <button type="button" class="primary-button" id="leave-direct-add">
                                <svg class="icon" aria-hidden="true" style="width: 16px; height: 16px;"><use href="#i-calendar"/></svg>
                                <span>+ Catat Cuti</span>
                            </button>
                        </div>
                    </div>

                    <!-- Top Metric Cards -->
                    <div class="metric-grid allowance-metric-grid" style="margin-bottom: 20px;">
                        <article class="metric-card" id="leave-card-pending" style="cursor: pointer;" title="Klik untuk melihat pengajuan yang menunggu">
                            <div class="metric-header">
                                <span class="metric-title">Menunggu Persetujuan</span>
                                <span class="metric-badge warning">Pending</span>
                            </div>
                            <strong id="leave-metric-pending">—</strong>
                            <small class="metric-sub">Permohonan cuti perlu ditinjau</small>
                        </article>
                        <article class="metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Disetujui</span>
                                <span class="metric-badge success">Approved</span>
                            </div>
                            <strong id="leave-metric-approved">—</strong>
                            <small class="metric-sub">Total cuti yang telah disetujui</small>
                        </article>
                        <article class="metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Status Saldo Tahunan</span>
                                <span class="metric-badge" id="leave-metric-year-badge">Checking…</span>
                            </div>
                            <strong id="leave-metric-year" style="font-size: 20px;">—</strong>
                            <small class="metric-sub" id="leave-metric-year-desc">Memuat status…</small>
                        </article>
                        <article class="metric-card">
                            <div class="metric-header">
                                <span class="metric-title">Karyawan Aktif</span>
                                <span class="metric-badge blue">Pegawai</span>
                            </div>
                            <strong id="leave-metric-employees">—</strong>
                            <small class="metric-sub">Karyawan dengan hak kuota</small>
                        </article>
                    </div>

                    <!-- Sub-tabs navigation -->
                    <div class="tabs" role="tablist" style="margin-bottom: 16px;">
                        <button type="button" role="tab" aria-selected="true" data-leave-tab="requests">
                            <svg class="icon" aria-hidden="true"><use href="#i-file-text"/></svg>
                            <span>Daftar Pengajuan Cuti</span>
                            <span class="badge" id="leave-pending-tab-badge" style="display: none; background: #b06000; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; margin-left: 4px;">0</span>
                        </button>
                        <button type="button" role="tab" aria-selected="false" data-leave-tab="balances">
                            <svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>
                            <span>Sisa Saldo &amp; Kuota Karyawan</span>
                        </button>
                        <button type="button" role="tab" aria-selected="false" data-leave-tab="settings">
                            <svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg>
                            <span>Konfigurasi Saldo Tahunan</span>
                        </button>
                    </div>

                    <!-- Tab 1: Pengajuan Cuti -->
                    <div id="leave-tab-panel-requests" class="leave-tab-panel">
                        <div class="panel">
                            <div class="panel-heading" style="padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
                                <div>
                                    <h2 style="font-size: 16px; margin: 0 0 4px;">Daftar Pengajuan Cuti &amp; Deposit Off</h2>
                                    <p id="leave-list-count" style="margin: 0; color: var(--muted); font-size: 13px;">Memuat data…</p>
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="search" id="leave-request-search" placeholder="Cari nama karyawan / alasan…" style="min-height: 38px; height: 38px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 13px; min-width: 220px;">
                                    <label class="sr-only" for="leave-status-filter">Filter status pengajuan</label>
                                    <select id="leave-status-filter" style="min-height: 38px; height: 38px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 13px;">
                                        <option value="">Semua Status</option>
                                        <option value="PENDING">Menunggu Persetujuan</option>
                                        <option value="APPROVED">Disetujui</option>
                                        <option value="REJECTED">Ditolak</option>
                                        <option value="CANCELLED">Dibatalkan</option>
                                    </select>
                                </div>
                            </div>
                            <div id="leave-table" aria-live="polite"></div>
                            <div id="leave-pagination" class="table-pagination"></div>
                        </div>
                    </div>

                    <!-- Tab 2: Sisa Saldo & Kuota Karyawan -->
                    <div id="leave-tab-panel-balances" class="leave-tab-panel" hidden>
                        <div class="panel">
                            <div class="panel-heading" style="padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
                                <div>
                                    <h2 style="font-size: 16px; margin: 0 0 4px;">Sisa Kuota Cuti Karyawan</h2>
                                    <p id="leave-balance-count" style="margin: 0; color: var(--muted); font-size: 13px;">Memuat saldo…</p>
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="search" id="leave-balance-search" placeholder="Cari karyawan / kode…" style="min-height: 38px; height: 38px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 13px; min-width: 200px;">
                                    <select id="leave-balance-dept-filter" style="min-height: 38px; height: 38px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 13px;">
                                        <option value="">Semua Departemen</option>
                                    </select>
                                </div>
                            </div>
                            <div id="leave-balance-table" aria-live="polite"></div>
                        </div>
                    </div>

                    <!-- Tab 3: Konfigurasi Saldo Tahunan -->
                    <div id="leave-tab-panel-settings" class="leave-tab-panel" hidden>
                        <div class="panel leave-year-panel" style="padding: 20px;">
                            <div class="panel-heading" style="margin-bottom: 16px; padding: 0 0 16px; border-bottom: 1px solid var(--line);">
                                <div>
                                    <h2 style="font-size: 16px; margin: 0 0 4px;">Pengaturan Kuota &amp; Saldo Tahunan</h2>
                                    <p id="leave-opening-state" style="margin: 0; color: var(--muted); font-size: 13px;">Memuat status saldo…</p>
                                </div>
                                <button type="button" class="primary-button" id="leave-opening-confirm" style="height: 40px; min-height: 40px; padding: 0 18px;">
                                    Konfirmasi Saldo Awal
                                </button>
                            </div>
                            <form id="leave-settings-form" class="inline-settings-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; align-items: flex-end;">
                                <label style="display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: var(--muted);">
                                    <span>Tahun Saldo</span>
                                    <input type="number" name="year" min="2000" max="2100" required style="min-height: 42px; height: 42px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 14px;">
                                </label>
                                <label style="display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: var(--muted);">
                                    <span>Cuti Reguler (Hari/Tahun)</span>
                                    <input type="number" name="regular_quota" min="0" max="60" required style="min-height: 42px; height: 42px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 14px;">
                                </label>
                                <label style="display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: var(--muted);">
                                    <span>Deposit Off (Hari/Tahun)</span>
                                    <input type="number" name="day_off_quota" min="0" max="60" required style="min-height: 42px; height: 42px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 14px;">
                                </label>
                                <label style="display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: var(--muted); grid-column: 1 / -1;">
                                    <span>Alasan Perubahan Kuota</span>
                                    <input name="reason" maxlength="1000" required placeholder="Contoh: Penyesuaian kuota standar tahunan 2026" style="min-height: 42px; height: 42px; padding: 0 12px; border: 1px solid #b9c4bc; border-radius: 8px; font-size: 14px;">
                                </label>
                                <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end;">
                                    <button type="submit" class="secondary-button" style="min-height: 42px; height: 42px; padding: 0 20px;">
                                        Simpan Kuota
                                    </button>
                                </div>
                                <p class="form-error" id="leave-settings-error" role="alert" hidden style="grid-column: 1 / -1;"></p>
                            </form>
                        </div>
                    </div>
                </section>
                <section class="view" data-view-panel="allowances" data-requires="admin">
                    <div class="page-heading">
                        <div>
                            <h1>Rekap Tunjangan</h1>
                            <span>Satu tunjangan makan dan transport per siklus dengan kehadiran tervalidasi dan kebijakan tarif aktif.</span>
                        </div>
                        <div class="heading-actions">
                            <a href="#settings/allowances" class="secondary-button" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                <svg class="icon" aria-hidden="true" style="width: 16px; height: 16px;"><use href="#i-sliders"/></svg>
                                <span>Tarif &amp; Kebijakan</span>
                            </a>
                        </div>
                    </div>

                    <div class="allowance-filter-card panel" style="padding: 16px 20px; margin-bottom: 20px;">
                        <form id="allowance-filters" class="allowance-filter-form">
                            <div class="allowance-presets-bar">
                                <span class="allowance-preset-label">Periode Cepat:</span>
                                <div class="allowance-preset-chips">
                                    <button type="button" class="preset-chip" data-preset="today">Hari Ini</button>
                                    <button type="button" class="preset-chip" data-preset="week">7 Hari Terakhir</button>
                                    <button type="button" class="preset-chip active" data-preset="month">Bulan Ini</button>
                                    <button type="button" class="preset-chip" data-preset="last-month">Bulan Lalu</button>
                                </div>
                            </div>
                            <div class="allowance-inputs-grid">
                                <label>
                                    <span>Dari Tanggal</span>
                                    <input type="date" name="from" id="allowance-from" required>
                                </label>
                                <label>
                                    <span>Sampai Tanggal</span>
                                    <input type="date" name="to" id="allowance-to" required>
                                </label>
                                <label>
                                    <span>Departemen</span>
                                    <select id="allowance-dept-filter">
                                        <option value="">Semua Departemen</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Level Karyawan</span>
                                    <select id="allowance-level-filter">
                                        <option value="">Semua Level</option>
                                        <option value="none">Belum Diatur</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Status Tunjangan</span>
                                    <select id="allowance-status-filter">
                                        <option value="">Semua Status</option>
                                        <option value="ELIGIBLE">Memenuhi Syarat</option>
                                        <option value="PENDING_VERIFICATION">Menunggu Verifikasi</option>
                                        <option value="CONFIGURATION_REQUIRED">Butuh Konfigurasi</option>
                                        <option value="NO_VERIFIED_ATTENDANCE">Tidak Ada Menit Valid</option>
                                    </select>
                                </label>
                                <label style="flex: 1 1 200px;">
                                    <span>Cari Karyawan</span>
                                    <input type="search" id="allowance-search" placeholder="Ketik nama atau NIK…">
                                </label>
                                <div class="allowance-action-btn">
                                    <button type="submit" class="primary-button" style="height: 42px; min-height: 42px; padding: 0 20px;">
                                        Tampilkan
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <p class="form-error" id="allowance-error" role="alert" hidden></p>

                    <div class="metric-grid allowance-metric-grid" style="margin-bottom: 24px;">
                        <article class="metric-card metric-eligible">
                            <div class="metric-header">
                                <span class="metric-title">Memenuhi Syarat</span>
                                <span class="metric-badge success">Eligible</span>
                            </div>
                            <strong id="allowance-eligible">—</strong>
                            <small class="metric-sub">Siklus berhak menerima tunjangan</small>
                        </article>
                        <article class="metric-card metric-pending">
                            <div class="metric-header">
                                <span class="metric-title">Menunggu Verifikasi</span>
                                <span class="metric-badge warning">Pending</span>
                            </div>
                            <strong id="allowance-pending">—</strong>
                            <small class="metric-sub">Perlu verifikasi kehadiran/izin</small>
                        </article>
                        <article class="metric-card metric-unconfigured">
                            <div class="metric-header">
                                <span class="metric-title">Butuh Konfigurasi</span>
                                <span class="metric-badge danger">Perlu Aksi</span>
                            </div>
                            <strong id="allowance-unconfigured">—</strong>
                            <small class="metric-sub" id="allowance-unconfigured-hint">Level atau tarif belum diset</small>
                        </article>
                        <article class="metric-card metric-total">
                            <div class="metric-header">
                                <span class="metric-title">Total Tunjangan</span>
                                <span class="metric-badge blue">Periode Ini</span>
                            </div>
                            <strong id="allowance-total" class="metric-highlight">—</strong>
                            <small class="metric-sub" id="allowance-subtotal">Makan: — | Transport: —</small>
                        </article>
                    </div>

                    <div class="panel">
                        <div class="panel-heading" style="padding: 16px 20px; border-bottom: 1px solid var(--line);">
                            <div>
                                <h2 style="font-size: 16px; margin: 0 0 4px;">Rincian Tunjangan Per Siklus Kerja</h2>
                                <p id="allowance-count" style="margin: 0; color: var(--muted); font-size: 13px;">Memuat data…</p>
                            </div>
                        </div>
                        <div id="allowance-table" aria-live="polite"></div>
                    </div>
                </section>
                <section class="view" data-view-panel="settings">
                    <div class="page-heading"><div><h1>Pengaturan</h1><span>Data dasar yang dipakai jadwal dan absensi.</span></div></div>
                    <div class="settings-view-layout">
                        <aside class="settings-nav-aside" aria-label="Kategori pengaturan">
                            <label class="settings-section-picker">
                                <span>Bagian</span>
                                <select id="settings-section-select">
                                    <option value="general">Umum</option>
                                    <option value="templates">Template shift</option>
                                    <option value="policies">Aturan kehadiran</option>
                                    <option value="attendance">Jendela presensi</option>
                                    <option value="departments">Departemen</option>
                                    <option value="positions">Jabatan</option>
                                    <option value="locations">Lokasi kerja</option>
                                    <option value="levels">Level karyawan</option>
                                    <option value="allowances">Tarif tunjangan</option>
                                    <option value="allowance-overrides">Pengecualian individu</option>
                                    <option value="users">Akun admin</option>
                                    <option value="backup">Backup database</option>
                                </select>
                            </label>
                            <div class="tabs settings-vertical-tabs" role="tablist" aria-orientation="vertical" aria-label="Bagian pengaturan">
                                <button type="button" role="tab" aria-selected="true" data-settings-tab="general"><svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg><span>Umum</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="templates" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg><span>Template shift</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="policies" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-shield-check"/></svg><span>Aturan kehadiran</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="attendance" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg><span>Jendela presensi</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="departments" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-building"/></svg><span>Departemen</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="positions" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-briefcase"/></svg><span>Jabatan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="locations" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"/></svg><span>Lokasi kerja</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="levels" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-briefcase"/></svg><span>Level karyawan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="allowances" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-coins"/></svg><span>Tarif tunjangan</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="allowance-overrides" data-requires="admin"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg><span>Pengecualian individu</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="users" data-requires="super_admin"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg><span>Akun admin</span></button>
                                <button type="button" role="tab" aria-selected="false" data-settings-tab="backup" data-requires="super_admin"><svg class="icon" aria-hidden="true"><use href="#i-download"/></svg><span>Backup database</span></button>
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
                                        <section class="site-settings-section">
                                            <h3>Monitoring usia pensiun</h3>
                                            <label>Usia pensiun perusahaan
                                                <input name="retirement_age_years" type="number" min="18" max="100" step="1">
                                                <small class="field-hint">Kosongkan sampai kebijakan ditetapkan. Karyawan akan ditandai mulai 12 bulan sebelum usia pensiun.</small>
                                                <small class="field-error" id="site-retirement-age-years-error" hidden></small>
                                            </label>
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
                            <form id="attendance-window-settings-form" class="site-settings-form" hidden>
                                <p class="form-error" id="attendance-window-settings-error" role="alert" hidden></p>
                                <div class="site-settings-layout">
                                    <div class="site-settings-fields">
                                        <section class="site-settings-section">
                                            <h3>Check-in lebih awal</h3>
                                            <label>Jendela sebelum jam mulai sesi
                                                <input name="checkin_early_minutes" type="number" min="0" max="720" step="1" required>
                                                <small class="field-hint">Contoh: 60 berarti check-in dibuka satu jam lebih awal. Nilai 0 membolehkan check-in kapan saja dan tetap menghitung jam kerja mulai dari awal shift.</small>
                                                <small class="field-error" id="attendance-window-checkin-early-minutes-error" hidden></small>
                                            </label>
                                        </section>
                                    </div>
                                </div>
                                <div class="site-settings-actions"><button type="submit" class="primary-button" id="attendance-window-settings-save">Simpan perubahan</button></div>
                            </form>
                            <div id="database-backup-section" class="database-backup-section" data-supported="{{ config('database.default') === 'mysql' ? 'true' : 'false' }}" hidden>
                                <p id="database-backup-error" class="form-error" role="alert" hidden></p>
                                <p id="database-backup-message" class="database-backup-message" role="status" aria-live="polite" hidden></p>
                                <div id="database-backup-unsupported" class="database-backup-unsupported" hidden>
                                    <h3>MySQL diperlukan</h3>
                                    <p>Backup dan restore tersedia saat aplikasi terhubung ke MySQL. Database SQLite lokal tidak didukung.</p>
                                </div>
                                <div id="database-backup-controls" class="database-backup-grid">
                                    <section class="database-backup-card">
                                        <h3>Unduh backup</h3>
                                        <p>File berisi seluruh database MySQL. Foto dan lampiran tersimpan terpisah dan tidak termasuk.</p>
                                        <button type="button" class="primary-button" id="database-backup-download"><svg class="icon" aria-hidden="true"><use href="#i-download"/></svg> Unduh backup database</button>
                                    </section>
                                    <section class="database-backup-card database-restore-card">
                                        <h3>Restore dari file</h3>
                                        <p>Semua tabel dan data saat ini akan diganti. Admin dan API tidak dapat digunakan selama pemulihan.</p>
                                        <form id="database-restore-form">
                                            <label>File backup (.sql.gz)
                                                <input id="database-restore-file" name="backup" type="file" accept=".gz,application/gzip" required>
                                                <small class="field-hint">Maksimal 100 MB. Gunakan file backup database yang dibuat oleh aplikasi.</small>
                                            </label>
                                            <label>Ketik RESTORE untuk melanjutkan
                                                <input id="database-restore-confirmation" name="confirmation" type="text" autocomplete="off" spellcheck="false" required>
                                            </label>
                                            <button type="submit" class="secondary-button" id="database-restore-submit" disabled>Restore database</button>
                                        </form>
                                    </section>
                                </div>
                                <div id="database-safety-backup" class="database-safety-backup" hidden>
                                    <h3>Backup pengaman</h3>
                                    <p>Salinan database sebelum restore terakhir disimpan privat di server sampai restore berikutnya.</p>
                                    <a class="secondary-button" href="/admin/api/database-backup/safety" download>Unduh backup pengaman terakhir</a>
                                </div>
                                <p id="database-safety-empty" class="database-safety-empty" hidden>Belum ada backup pengaman. Salinan akan dibuat otomatis sebelum restore pertama.</p>
                            </div>
                            <div id="settings-table" aria-live="polite"></div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <dialog class="app-dialog schedule-editor-dialog" id="schedule-modal" aria-labelledby="schedule-modal-title">
        <form method="dialog" id="schedule-form" novalidate>
            <div class="modal-heading"><div><h2 id="schedule-modal-title">Buat jadwal</h2><p>Jadwal baru tersimpan sebagai draft. Karyawan melihatnya setelah diterbitkan.</p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button></div>
            <div class="modal-body">
                <div class="schedule-modal-top-grid">
                    <label>Karyawan<select id="schedule-employee" required></select></label>
                    <label>Tanggal kerja<input type="date" id="schedule-date" required></label>
                    <label>Jenis shift<select id="schedule-mode"><option value="template">Shift reguler</option><option value="custom">Split khusus</option></select></label>
                    <label id="schedule-template-wrap">Shift reguler<select id="schedule-template" required></select></label>
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
    <dialog class="app-dialog schedule-bulk-dialog" id="schedule-bulk-modal" aria-labelledby="schedule-bulk-modal-title">
        <form method="dialog" id="schedule-bulk-form" novalidate>
            <div class="modal-heading">
                <div>
                    <h2 id="schedule-bulk-modal-title">Penugasan Jadwal Massal</h2>
                    <p>Tugaskan shift ke banyak karyawan dan rentang tanggal sekaligus dalam status draft.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
            </div>
            <div class="modal-body bulk-modal-body">
                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>1. Pilih Karyawan</strong>
                        <span class="bulk-counter" id="bulk-emp-counter">0 dipilih</span>
                    </div>
                    <div class="bulk-emp-toolbar">
                        <input type="search" id="bulk-emp-search" placeholder="Cari nama karyawan..." class="bulk-search-input" aria-label="Cari nama karyawan">
                        <select id="bulk-dept-filter" class="bulk-dept-select" aria-label="Filter departemen">
                            <option value="all">Semua Departemen</option>
                        </select>
                        <div class="bulk-quick-actions">
                            <button type="button" class="text-button" id="bulk-select-all">Pilih Semua</button>
                            <span class="sep">|</span>
                            <button type="button" class="text-button" id="bulk-clear-all">Batal</button>
                        </div>
                    </div>
                    <div class="bulk-emp-list" id="bulk-emp-list" role="group" aria-label="Daftar karyawan"></div>
                </div>

                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>2. Rentang Tanggal &amp; Hari Kerja</strong>
                    </div>
                    <div class="schedule-modal-top-grid">
                        <label>Tanggal mulai<input type="date" id="bulk-start-date" required></label>
                        <label>Tanggal selesai<input type="date" id="bulk-end-date" required></label>
                    </div>
                    <div class="bulk-days-wrap">
                        <div class="bulk-days-header">
                            <span>Hari yang ditugaskan:</span>
                            <div class="bulk-day-presets">
                                <button type="button" class="preset-pill active" data-preset="weekdays">Senin – Jumat</button>
                                <button type="button" class="preset-pill" data-preset="all">Setiap Hari</button>
                                <button type="button" class="preset-pill" data-preset="weekend">Sabtu – Minggu</button>
                            </div>
                        </div>
                        <div class="bulk-days-checkboxes" id="bulk-days-group">
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="1" checked><span>Sen</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="2" checked><span>Sel</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="3" checked><span>Rab</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="4" checked><span>Kam</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="5" checked><span>Jum</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="6"><span>Sab</span></label>
                            <label class="day-checkbox-label"><input type="checkbox" name="bulk_day" value="0"><span>Min</span></label>
                        </div>
                    </div>
                </div>

                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>3. Shift Reguler &amp; Aturan</strong>
                    </div>
                    <label>Shift reguler<select id="bulk-template-select" required></select></label>
                    <label class="check-field bulk-check-field">
                        <input type="checkbox" id="bulk-overwrite-checkbox">
                        <span>Timpa jadwal draft yang sudah ada pada tanggal yang sama</span>
                        <small class="field-hint">Jika tidak dicentang, tanggal yang sudah memiliki jadwal akan dilewati secara otomatis.</small>
                    </label>
                </div>

                <p class="form-error" id="bulk-modal-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="submit" class="primary-button" id="bulk-submit-btn">Tugaskan Massal</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog schedule-swap-dialog" id="schedule-swap-modal" aria-labelledby="schedule-swap-title">
        <form id="schedule-swap-form" novalidate>
            <div class="modal-heading">
                <div>
                    <h2 id="schedule-swap-title">Tukar siklus jadwal</h2>
                    <p id="schedule-swap-subtitle">Tukar jadwal karyawan atau susun rantai pertukaran shift.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
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
                    <h2 id="employee-import-modal-title">Impor Data Karyawan (CSV)</h2>
                    <p id="employee-import-modal-hint">Daftarkan data karyawan secara massal menggunakan berkas spreadsheet CSV.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
            </div>
            <div class="modal-body">
                <div class="form-fields" style="display: flex; flex-direction: column; gap: 16px;">
                    <!-- Langkah 1: Template CSV -->
                    <div class="import-template-banner">
                        <div class="import-template-info">
                            <div class="import-template-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                            </div>
                            <div class="import-template-text">
                                <strong>Format Kolom Spreadsheet</strong>
                                <small>Gunakan template resmi sistem agar format kode, nama, departemen, dan jabatan sesuai.</small>
                            </div>
                        </div>
                        <button type="button" id="employee-template-modal-btn" class="secondary-button" style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; height: 38px; min-height: 38px; padding: 0 14px; white-space: nowrap;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <span>Unduh Template CSV</span>
                        </button>
                    </div>

                    <!-- Langkah 2: Upload Area / Dropzone -->
                    <div class="import-dropzone" id="employee-import-dropzone">
                        <input type="file" id="employee-import-file" accept=".csv,text/csv,text/plain" style="position: absolute; inset: 0; opacity: 0; cursor: pointer; z-index: 10;">
                        <div id="employee-import-dropzone-idle" style="display: flex; flex-direction: column; align-items: center;">
                            <div class="import-dropzone-icon">
                                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/><polyline points="16 16 12 12 8 16"/></svg>
                            </div>
                            <div class="import-dropzone-title">Pilih atau Tarik Berkas CSV ke Sini</div>
                            <div class="import-dropzone-desc">Mendukung berkas <code>.csv</code> UTF-8 dengan pemisah koma (<code>,</code>) atau titik koma (<code>;</code>). Maksimal 5MB.</div>
                            <button type="button" class="secondary-button" style="pointer-events: none; font-size: 13px; height: 36px; min-height: 36px;">Telusuri Berkas</button>
                        </div>
                        <div id="employee-import-dropzone-selected" class="import-selected-file" hidden>
                            <div class="import-file-meta">
                                <div class="import-file-badge">CSV</div>
                                <div class="import-file-info">
                                    <div class="import-file-name" id="employee-import-file-name">-</div>
                                    <div class="import-file-size" id="employee-import-file-size">-</div>
                                </div>
                            </div>
                            <button type="button" id="employee-import-file-clear" class="secondary-button" style="font-size: 12px; height: 34px; min-height: 34px; padding: 0 12px; z-index: 20;">Ganti File</button>
                        </div>
                    </div>

                    <!-- Langkah 3: Pratinjau & Validasi Data -->
                    <div id="employee-import-preview-container" hidden style="display: flex; flex-direction: column; gap: 10px;">
                        <div class="import-stats-banner">
                            <div class="import-stats-summary" id="employee-import-stats-summary">
                                <span>Hasil Verifikasi Berkas</span>
                            </div>
                            <div class="import-stats-pills">
                                <span class="import-pill total" id="import-pill-total">Total: 0</span>
                                <span class="import-pill valid" id="import-pill-valid">Valid: 0</span>
                                <span class="import-pill error" id="import-pill-error" hidden>Error: 0</span>
                            </div>
                        </div>

                        <!-- Filter Tabs -->
                        <div class="import-filter-tabs">
                            <button type="button" class="import-filter-btn active" data-filter="all" id="import-filter-all">Semua Baris (<span id="count-filter-all">0</span>)</button>
                            <button type="button" class="import-filter-btn" data-filter="valid" id="import-filter-valid">Siap Diimpor (<span id="count-filter-valid">0</span>)</button>
                            <button type="button" class="import-filter-btn" data-filter="error" id="import-filter-error">Bermasalah (<span id="count-filter-error">0</span>)</button>
                        </div>

                        <div class="import-table-wrap">
                            <table class="import-table">
                                <thead>
                                    <tr>
                                        <th style="width: 48px; text-align: center;">#</th>
                                        <th style="width: 80px;">Status</th>
                                        <th style="width: 100px;">Kode</th>
                                        <th>Nama Lengkap</th>
                                        <th>Departemen</th>
                                        <th>Jabatan</th>
                                        <th>Status Kerja</th>
                                        <th style="min-width: 180px;">Keterangan / Validasi</th>
                                    </tr>
                                </thead>
                                <tbody id="employee-import-table-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Info Progress Impor Berkas -->
                    <div id="employee-import-progress-wrap" hidden class="import-progress-card">
                        <div class="import-progress-header">
                            <div class="import-progress-label">
                                <svg class="import-spinner" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>
                                <strong id="employee-import-progress-status">Memproses impor data…</strong>
                            </div>
                            <span class="import-progress-pct" id="employee-import-progress-pct">0%</span>
                        </div>
                        <div class="import-progress-bar-bg">
                            <div class="import-progress-bar-fill" id="employee-import-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="import-progress-detail" id="employee-import-progress-detail">
                            0 dari 0 data karyawan diproses
                        </div>
                    </div>

                    <p class="form-error" id="employee-import-error" role="alert" hidden></p>
                </div>
            </div>
            <div class="modal-actions" style="justify-content: space-between; align-items: center;">
                <div style="font-size: 12px; color: var(--muted);" id="employee-import-notice">
                    Baris dengan kesalahan format akan dilewati secara otomatis.
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="secondary-button" data-close-modal>Batal</button>
                    <button type="submit" class="primary-button" id="employee-import-submit" disabled>Simpan & Impor Karyawan</button>
                </div>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog overtime-order-dialog" id="overtime-order-modal" aria-labelledby="overtime-order-modal-title">
        <form id="overtime-order-form" novalidate>
            <div class="modal-heading">
                <div>
                    <h2 id="overtime-order-modal-title">Terbitkan Surat Perintah Lembur (SPL)</h2>
                    <p>Tugaskan perintah lembur resmi kepada karyawan untuk tanggal dan jam tertentu.</p>
                </div>
                <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
            </div>
            <div class="modal-body bulk-modal-body">
                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>1. Pilih Karyawan yang Ditugaskan Lembur</strong>
                        <span class="bulk-counter" id="overtime-emp-counter">0 dipilih</span>
                    </div>
                    <div class="bulk-emp-toolbar">
                        <input type="search" id="overtime-emp-search" placeholder="Cari nama karyawan..." class="bulk-search-input" aria-label="Cari nama karyawan">
                        <select id="overtime-dept-filter" class="bulk-dept-select" aria-label="Filter departemen">
                            <option value="all">Semua Departemen</option>
                        </select>
                        <div class="bulk-quick-actions">
                            <button type="button" class="text-button" id="overtime-select-all">Pilih Semua</button>
                            <span class="sep">|</span>
                            <button type="button" class="text-button" id="overtime-clear-all">Batal</button>
                        </div>
                    </div>
                    <div class="bulk-emp-list" id="overtime-emp-list" role="group" aria-label="Daftar karyawan lembur"></div>
                </div>

                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>2. Waktu &amp; Tanggal Lembur</strong>
                    </div>
                    <div class="schedule-modal-top-grid">
                        <label>Tanggal kerja
                            <input type="date" id="overtime-work-date" required>
                        </label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label>Jam mulai
                                <input type="time" id="overtime-start-time" required>
                            </label>
                            <label>Jam selesai
                                <input type="time" id="overtime-end-time" required>
                            </label>
                        </div>
                    </div>
                    <input type="hidden" id="overtime-start-day-offset" value="0">
                    <input type="hidden" id="overtime-end-day-offset" value="0">
                    <label class="check-field" style="margin-top: 8px;">
                        <input type="checkbox" id="overtime-cross-day">
                        <span>Lembur lintas hari (melewati tengah malam / selesai esok hari)</span>
                    </label>
                    <div id="overtime-calc-info" class="field-hint" style="margin-top: 6px; font-weight: 600; color: var(--primary);"></div>
                </div>

                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <strong>3. Uraian Pekerjaan Lembur</strong>
                    </div>
                    <label>Judul / Uraian Tugas Lembur
                        <input type="text" id="overtime-title" placeholder="Contoh: Penanganan acara banquet malam" required minlength="3" maxlength="150">
                    </label>
                    <label style="margin-top: 8px;">Catatan Tambahan (Opsional)
                        <textarea id="overtime-notes" rows="2" maxlength="500" placeholder="Instruksi khusus atau catatan pengawas..."></textarea>
                    </label>
                </div>

                <p class="form-error" id="overtime-modal-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="submit" class="primary-button" id="overtime-submit-btn">Terbitkan SPL</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="form-modal" aria-labelledby="form-modal-title">
        <form method="dialog" id="form-modal-form" novalidate>
            <div class="modal-heading">
                <div>
                    <h2 id="form-modal-title"></h2>
                    <p id="form-modal-hint"></p>
                </div>
                <button type="button" class="icon-button modal-close-btn" data-close-modal aria-label="Tutup dialog">
                    <svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="form-modal-fields" class="form-fields"></div>
                <p class="form-error" id="form-modal-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="submit" class="primary-button" id="form-modal-submit">Simpan</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="workflow-modal" aria-labelledby="workflow-modal-title">
        <form id="workflow-modal-form">
            <div class="modal-heading">
                <div>
                    <h2 id="workflow-modal-title"></h2>
                    <p id="workflow-modal-hint"></p>
                </div>
                <button type="button" class="icon-button modal-close-btn" data-close-modal aria-label="Tutup dialog">
                    <svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="workflow-modal-fields" class="form-fields"></div>
                <p class="form-error" id="workflow-modal-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-modal>Batal</button>
                <button type="submit" class="primary-button" id="workflow-modal-submit">Simpan</button>
            </div>
        </form>
    </dialog>
    <dialog class="app-dialog notification-dialog" id="notification-modal" aria-labelledby="notification-modal-title">
        <div class="modal-heading">
            <div><h2 id="notification-modal-title">Notifikasi</h2><p>Persetujuan cuti dan izin terbaru.</p></div>
            <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
        </div>
        <div class="modal-body">
            <p class="form-error" id="notification-error" role="alert" hidden></p>
            <div id="notification-list" class="notification-list" aria-live="polite"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="secondary-button" data-open-approvals>Buka persetujuan</button>
            <button type="button" class="primary-button" data-close-modal>Tutup</button>
        </div>
    </dialog>
    <dialog class="app-dialog photo-preview-dialog" id="photo-preview-modal" aria-labelledby="photo-preview-title">
        <div class="modal-heading">
            <div><h2 id="photo-preview-title">Foto Presensi</h2></div>
            <button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog" onclick="document.getElementById('photo-preview-modal').close()">
                <svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>
            </button>
        </div>
        <div class="modal-body" style="text-align: center;">
            <div style="border-radius: 12px; overflow: hidden; background: #0c1c14; display: flex; justify-content: center; align-items: center; min-height: 260px; max-height: 460px; border: 1px solid var(--line);">
                <img id="photo-preview-img" src="" alt="Foto Presensi" style="max-width: 100%; max-height: 440px; object-fit: contain;">
            </div>
            <p id="photo-preview-caption" style="margin: 12px 0 0; color: var(--muted); font-size: 13px; font-weight: 500;"></p>
        </div>
        <div class="modal-actions" style="justify-content: flex-end;">
            <button type="button" class="secondary-button" onclick="document.getElementById('photo-preview-modal').close()">Tutup</button>
        </div>
    </dialog>
    <dialog class="app-dialog confirm-dialog" id="confirm-modal" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message">
        <form method="dialog">
            <div class="modal-heading"><div><h2 id="confirm-modal-title">Konfirmasi</h2></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button></div>
            <div class="modal-body"><p id="confirm-modal-message"></p></div>
            <div class="modal-actions"><button type="submit" class="secondary-button" value="cancel" autofocus>Batal</button><button type="submit" class="primary-button" id="confirm-modal-submit" value="confirm">Lanjutkan</button></div>
        </form>
    </dialog>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
