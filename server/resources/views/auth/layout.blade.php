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
                <address class="auth-contact" aria-label="Kontak website">
                    @if ($siteSettings->contact_email)
                        <a href="mailto:{{ $siteSettings->contact_email }}">{{ $siteSettings->contact_email }}</a>
                    @endif
                    @if ($siteSettings->contact_phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteSettings->contact_phone) }}">{{ $siteSettings->contact_phone }}</a>
                    @endif
                    @if ($siteSettings->contact_address)
                        <span>{{ $siteSettings->contact_address }}</span>
                    @endif
                </address>
            @endif
        </div>
    </main>
</body>
</html>
