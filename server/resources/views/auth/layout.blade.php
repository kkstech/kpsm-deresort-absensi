<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title') · {{ $siteSettings->site_name }}</title>
    @if ($siteSettings->favicon_url)
        <link rel="icon" href="{{ $siteSettings->favicon_url }}">
    @endif
    @vite(['resources/css/app.css'])
</head>
<body>
    <main class="auth-page">
        <div class="auth-card">
            @yield('content')
            @if ($siteSettings->contact_email || $siteSettings->contact_phone || $siteSettings->contact_address)
                <address class="auth-contact" aria-label="Bantuan & Kontak">
                    <div class="auth-contact-header">
                        <svg viewBox="0 0 24 24" class="icon" aria-hidden="true" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.2; flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        <span>Bantuan &amp; Kontak Pengelola</span>
                    </div>
                    <div class="auth-contact-items">
                        @if ($siteSettings->contact_email)
                            <div class="auth-contact-item">
                                <span class="auth-contact-label">Email:</span>
                                <a href="mailto:{{ $siteSettings->contact_email }}">{{ $siteSettings->contact_email }}</a>
                            </div>
                        @endif
                        @if ($siteSettings->contact_phone)
                            <div class="auth-contact-item">
                                <span class="auth-contact-label">No. Telp:</span>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteSettings->contact_phone) }}">{{ $siteSettings->contact_phone }}</a>
                            </div>
                        @endif
                        @if ($siteSettings->contact_address)
                            <div class="auth-contact-item full-width">
                                <span class="auth-contact-label">Alamat:</span>
                                <span>{{ $siteSettings->contact_address }}</span>
                            </div>
                        @endif
                    </div>
                </address>
            @endif
        </div>
    </main>
</body>
</html>
