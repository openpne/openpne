<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create an account - {{ config('app.name') }}</title>
</head>
<body>
    <h1>Create an account</h1>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <p>
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>
        </p>
        <p>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required>
        </p>
        <p>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
        </p>
        <p>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required>
        </p>
        <button type="submit">Create account</button>
    </form>

    <p><a href="{{ route('login') }}">Already have an account? Sign in</a></p>
</body>
</html>
