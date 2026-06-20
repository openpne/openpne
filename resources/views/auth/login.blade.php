@extends('layouts.classic')

@section('title', __('Login'))

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @include('partials.gadget-sections', ['zones' => $zones])
@else
    {{-- No login gadgets configured: the fixed single-column login form. --}}
    @section('content')
        @include('partials.login-form')
    @endsection
@endif
