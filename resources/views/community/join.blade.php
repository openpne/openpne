@extends('layouts.classic')

@section('title', __('Join this %community%'))

@section('content')
    {{-- OpenPNE 3 joinInput.php's form kind: the question is the form's `body`, rendered as a
         .block inside the form tag. --}}
    <x-classic.parts id="communityJoining" name="form" :title="__('Join this %community%')">
        <form method="POST" action="{{ route('community.join') }}">
            @csrf
            <input type="hidden" name="id" value="{{ $community->getKey() }}">
            <div class="block">
                @if ($community->register_policy === \App\Features\Community\JoinPolicy::Approval)
                    {{ __('This %community% requires admin approval. Send a join request to :name?', ['name' => $community->name]) }}
                @else
                    {{ __('Join :name?', ['name' => $community->name]) }}
                @endif
            </div>
            {{-- joinInput.php's firstRow: the form kind's table carries no fields here, only the
                 preview of what is being joined. --}}
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
                    <li><input type="submit" class="input_submit" value="{{ __('Join') }}"></li>
                    <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
