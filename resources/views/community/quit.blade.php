@extends('layouts.classic')

@section('title', __('Leave this %community%'))

@section('content')
    <x-classic.parts id="communityQuiting" name="form" :title="__('Leave this %community%')">
        <form method="POST" action="{{ route('community.quit') }}">
            @csrf
            <input type="hidden" name="id" value="{{ $community->getKey() }}">
            <div class="block">{{ __('Leave :name?', ['name' => $community->name]) }}</div>
            {{-- quitSuccess.php's firstRow: the form kind's table carries no fields here, only the
                 preview of what is being left. --}}
            <table>
                <tr>
                    <th>{{ __('Photo') }}</th>
                    <td><a href="{{ route('community.show', $community) }}"><x-classic.image :file="$community->image" :size="76" :alt="$community->name" /></a> </td>
                </tr>
                <tr>
                    <th>{{ __('%Community%') }}</th>
                    <td><a href="{{ route('community.show', $community) }}">{{ $community->name }}</a></td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Leave this %community%') }}"></li>
                    <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
