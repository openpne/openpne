@extends('layouts.classic')

@section('title', __('Register'))

@section('content')
    <x-classic.parts id="noRegisterBoxError" name="box" :title="__('Register')">
        <div class="body">
            @include('auth._registration-paused')
        </div>
    </x-classic.parts>
@endsection
