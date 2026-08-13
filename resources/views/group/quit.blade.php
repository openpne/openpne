@extends('layouts.classic')

@section('title', __('Leave this %community%'))

@section('content')
    <x-classic.parts id="communityQuiting" name="form" :title="__('Leave this %community%')">
        <form method="POST" action="{{ route('group.quit', $group) }}">
            @csrf
            <input type="hidden" name="id" value="{{ $group->getKey() }}">
            <div class="block">{{ __('Leave :name?', ['name' => $group->name]) }}</div>
            {{-- quitSuccess.php's firstRow: the form kind's table carries no fields here, only the
                 preview of what is being left. --}}
            <table>
                <tr>
                    <th>{{ __('Photo') }}</th>
                    <td><a href="{{ route('group.show', $group) }}"><x-classic.image :file="$group->image" :size="76" :alt="$group->name" /></a> </td>
                </tr>
                <tr>
                    <th>{{ __('%Community%') }}</th>
                    <td><a href="{{ route('group.show', $group) }}">{{ $group->name }}</a></td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Leave this %community%') }}"></li>
                    <li><a href="{{ route('group.show', $group) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
