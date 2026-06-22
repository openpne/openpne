@extends('layouts.classic')

@section('title', __('Delete messages'))

@section('content')
    {{-- OpenPNE 3 deleteListConfirm: the trash bulk-purge re-submits the checked ids after
         confirmation. Rendered from the bulk action, so the body id stays page_message_list. --}}
    <div class="dparts box" id="formMessageDeleteList">
        <div class="parts">
            <div class="partsHeading"><h3>{{ __('Delete messages') }}</h3></div>
            <div class="block">
                <p>{{ __('Do you delete messages?') }}</p>
                <form method="POST" action="{{ route('message.bulk') }}">
                    @csrf
                    <input type="hidden" name="box" value="{{ \App\Features\Message\MessageBox::Trash->value }}">
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="confirm" value="1">
                    @foreach ($ids as $id)
                        <input type="hidden" name="ids[]" value="{{ $id }}">
                    @endforeach
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
