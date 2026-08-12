@extends('layouts.classic')

@section('title', $box->heading())

@section('sidemenu')
    <x-message.sidemenu :current="$box" />
@endsection

@section('content')
    @php($openRoute = $box->openRoute())
    @php($isTrash = $box === \App\Features\DirectMessage\DirectMessageBox::Trash)
    @php($toggleAll = fn (string $checked): string => 'this.closest(\'form\').querySelectorAll(\'input[name="ids[]"]\').forEach(function(c){c.checked='.$checked.'});return false')
    <x-classic.parts id="message_list" name="searchResultList" :title="$box->heading()">
        @if ($messages->isEmpty())
            <div class="body">{{ __('There are no messages') }}</div>
        @else
            {{-- The band stays on every box; only the inbox fills it, since only its rows can carry
                 the replied icon. --}}
            <div class="pagerRelativeMulti">@if ($box === \App\Features\DirectMessage\DirectMessageBox::Receive)<p class="icons"><span><img src="{{ asset('opMessagePlugin/images/'.\App\Features\DirectMessage\DirectMessageRowStatus::Replied->icon()) }}" alt=""> {{ __('Replied') }}</span></p>@endif</div>
            {{-- OpenPNE 3 nests each pager inside a div.pagerRelative > p.number the template opens
                 itself, so its _pagerNavigation.php emits a second one inside — the pager stands
                 alone here instead. --}}
            <x-classic.pager :paginator="$messages" />

            {{-- The list is a form whose checked rows the buttons act on (trash from
                 receive/send/draft, restore/purge from trash). --}}
            <form method="POST" action="{{ route('message.bulk') }}" name="delete_message">
                @csrf
                <input type="hidden" name="box" value="{{ $box->value }}">
                <table>
                    <col class="status">
                    <col class="delete">
                    <col class="target">
                    <col class="title">
                    <col class="date">
                    <tr>
                        <th></th>
                        <th class="delete">{{ __('Delete') }}</th>
                        <th>{{ $box->counterpartyHeading() }}</th>
                        <th>{{ __('Subject') }}</th>
                        <th>{{ __('Created At') }}</th>
                    </tr>
                    @foreach ($messages as $item)
                        {{-- OpenPNE 3 marks an unread received row with class="unread". --}}
                        <tr @class(['unread' => $item->unread])>
                            <td class="status"><span><img src="{{ asset('opMessagePlugin/images/'.$item->status->icon()) }}" alt="{{ $item->status->label() }}"></span></td>
                            <td><span><input type="checkbox" name="ids[]" value="{{ $item->messageId }}" aria-label="{{ $item->subject !== '' ? $item->subject : __('(No subject)') }}"></span></td>
                            <td><span>
                                @if ($item->counterparty)
                                    <a href="{{ route('member.profile.show', $item->counterparty) }}">{{ $item->counterparty->name }}</a>
                                @else
                                    {{ __('Withdrawn member') }}
                                @endif
                            </span></td>
                            <td><span><a href="{{ route($openRoute, ['message' => $item->messageId]) }}">{{ $item->subject }}</a></span></td>
                            <td><span>{{ \App\Support\LocalizedDate::dateTime($item->date) }}</span></td>
                        </tr>
                    @endforeach
                </table>

                <x-classic.pager :paginator="$messages" />

                <div class="operation">
                    <p>
                        {{-- OpenPNE 3's own labels (Check All / Clear All): a visible parity
                             element, so its screen wording wins over key reuse. --}}
                        <a href="#" onclick="{{ $toggleAll('true') }}">{{ __('Check All') }}</a> /
                        <a href="#" onclick="{{ $toggleAll('false') }}">{{ __('Clear All') }}</a>
                    </p>
                    <ul class="moreInfo button">
                        @if ($isTrash)
                            <li><button type="submit" name="action" value="restore" class="input_submit">{{ __('Restore') }}</button></li>
                            <li><button type="submit" name="action" value="purge" class="input_submit">{{ __('Delete') }}</button></li>
                        @else
                            <li><button type="submit" name="action" value="delete" class="input_submit">{{ __('Delete') }}</button></li>
                        @endif
                    </ul>
                </div>
            </form>
        @endif
    </x-classic.parts>
@endsection
