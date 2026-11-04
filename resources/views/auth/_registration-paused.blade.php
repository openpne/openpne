<p>{{ __('Registration is paused for now.') }}</p>
<p>{{ __('Please wait until it reopens.') }}</p>
<p>
    {{ __('If it is urgent, you can send a request to the administrator.') }}
    <form method="POST" action="{{ route('register.capacity-request') }}">
        @csrf
        <input type="submit" class="input_submit" value="{{ __('Send a request to the administrator') }}">
    </form>
</p>
