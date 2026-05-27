<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in - {{ config('app.name') }}</title>
</head>
<body>
    <h1>Sign in</h1>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <p>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </p>
        <p>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
        </p>
        <p>
            <label>
                <input type="checkbox" name="remember">
                Remember me
            </label>
        </p>
        <button type="submit">Sign in</button>
    </form>

    <p><a href="{{ route('register') }}">Create an account</a></p>
</body>
</html>
