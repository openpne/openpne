@extends('layouts.classic')

@section('title', __('Delete post'))

@section('content')
    {{-- OpenPNE 3 confirms a deletion inline in JavaScript rather than on a page, so this follows the
         box + .block shape its hand-written diary/message confirmations use. --}}
    <x-classic.parts id="timeline_delete" name="box" :title="__('Delete post')">
        <div class="block">
            <p>{{ __('Delete this post?') }}</p>
            <form method="POST" action="{{ route('timeline.delete', $post) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        <li><a href="{{ route('timeline.show', $post) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
