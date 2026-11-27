@extends('layouts.classic')

@section('title', __('Admin panel'))

@section('content')
    <x-classic.parts id="adminEntry" name="form" :title="__('Admin panel')">
        <p>{{ __('Please confirm it is you before opening the admin panel.') }}</p>

        <form method="POST" action="{{ route('member.admin_entry.store') }}">
            @csrf
            <table>
                @include('member.partials.reauth-fields', ['part' => 'rows', 'idPrefix' => 'admin_entry', 'purpose' => 'admin-entry'])
            </table>
            @include('member.partials.reauth-fields', ['part' => 'recovery', 'idPrefix' => 'admin_entry'])
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Open the admin panel') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
