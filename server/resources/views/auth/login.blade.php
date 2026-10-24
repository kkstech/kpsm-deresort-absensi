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
    <p>Pakai email dan password yang dibuat admin.</p>
    <form method="post" action="{{ route('login') }}" class="auth-form">
        @csrf
        @if ($errors->any())
            <p class="form-error" role="alert">{{ $errors->first() }}</p>
        @endif
        <label>Email
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </label>
        <label>Password
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <label class="auth-remember"><input name="remember" type="checkbox" value="1" @checked(old('remember'))><span>Ingat saya di perangkat ini</span></label>
        <button class="primary-button" type="submit">Masuk</button>
    </form>
@endsection
