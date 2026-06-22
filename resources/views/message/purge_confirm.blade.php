@extends('layouts.classic')

@section('title', __('Delete this message'))

@section('content')
    {{-- OpenPNE 3 deleteConfirm: a single trashed message purged for good after confirmation. --}}
    <div class="dparts box" id="formMessageDelete">
        <div class="parts">
            <div class="partsHeading"><h3>{{ __('Delete this message') }}</h3></div>
            <div class="block">
                <p>{{ __('Do you delete this message?') }}</p>
                <form method="POST" action="{{ route('message.trash.purge', ['message' => $message->getKey()]) }}">
                    @csrf
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><button type="submit" class="input_submit">{{ __('Delete') }}</button></li>
                            <li><a href="{{ route('message.trash') }}">{{ __('Cancel') }}</a></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
