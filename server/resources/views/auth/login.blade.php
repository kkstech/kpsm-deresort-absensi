@extends('auth.layout')
@section('title', 'Masuk')
@section('content')
    @if ($siteSettings->logo_url)
        <img class="auth-brand-logo" src="{{ $siteSettings->logo_url }}" alt="{{ $siteSettings->site_name }}">
    @else
        <span class="auth-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><ellipse cx="12" cy="12" rx="4" ry="9"></ellipse><path d="M3.5 9h17M3.5 15h17"></path></svg>
        </span>
    @endif
    <h1>Masuk ke admin</h1>
    <p>Pakai username dan password akun admin.</p>
    <form method="post" action="{{ route('login') }}" class="auth-form">
        @csrf
        @if ($errors->any())
            <p class="form-error" role="alert">{{ $errors->first() }}</p>
        @endif
        <label>Username
            <input name="username" type="text" value="{{ old('username') }}" autocomplete="username" placeholder="Username atau email" required autofocus autocapitalize="none" autocorrect="off" spellcheck="false">
        </label>
        <label>Password
            <div class="password-wrapper">
                <input id="password-field" name="password" type="password" autocomplete="current-password" placeholder="Password akun" required>
                <button type="button" class="password-toggle-btn" aria-label="Lihat password" onclick="togglePasswordVisibility()">
                    <svg id="eye-icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg id="eye-icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display: none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                        <line x1="1" y1="1" x2="23" y2="23"></line>
                    </svg>
                </button>
            </div>
        </label>
        <label class="auth-remember"><input name="remember" type="checkbox" value="1" @checked(old('remember'))><span>Ingat saya di perangkat ini</span></label>
        <button class="primary-button" type="submit">Masuk</button>
    </form>
    <script>
        function togglePasswordVisibility() {
            var field = document.getElementById('password-field');
            var iconShow = document.getElementById('eye-icon-show');
            var iconHide = document.getElementById('eye-icon-hide');
            if (field.type === 'password') {
                field.type = 'text';
                iconShow.style.display = 'none';
                iconHide.style.display = 'block';
            } else {
                field.type = 'password';
                iconShow.style.display = 'block';
                iconHide.style.display = 'none';
            }
        }
    </script>
@endsection
