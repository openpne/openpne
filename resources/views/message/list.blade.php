@extends('layouts.classic')

@section('title', $box->heading())

@section('sidemenu')
    <x-message.sidemenu :current="$box" />
@endsection

@section('content')
    @php($showRoute = $box->showRoute())
    @php($isTrash = $box === \App\Features\Message\MessageBox::Trash)
    <div class="dparts searchResultList" id="message_list">
        <div class="parts">
            <div class="partsHeading"><h3>{{ $box->heading() }}</h3></div>
            @if ($messages->isEmpty())
                <div class="body">{{ __('There are no messages') }}</div>
            @else
                {{-- OpenPNE 3 MessageDeleteForm: the list is a form whose checked rows the buttons act
                     on (trash from receive/send/draft, restore/purge from trash). --}}
                <form method="POST" action="{{ route('message.bulk') }}" name="delete_message">
                    @csrf
                    <input type="hidden" name="box" value="{{ $box->value }}">
                    <table>
                        <tr>
                            <th></th>
                            <th class="delete"><input type="checkbox" aria-label="{{ __('Select All') }}" onclick="var on=this.checked;this.closest('form').querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(function(c){c.checked=on})"></th>
                            <th>{{ $box->counterpartyHeading() }}</th>
                            <th>{{ __('Subject') }}</th>
                            <th>{{ __('Created At') }}</th>
                        </tr>
                        @foreach ($messages as $item)
                            {{-- OpenPNE 3 marks an unread received row with class="unread". --}}
                            <tr @class(['unread' => $item->unread])>
                                <td class="status"><span @class(['read' => ! $item->unread, 'unread' => $item->unread])></span></td>
                                <td class="delete"><input type="checkbox" name="ids[]" value="{{ $item->messageId }}"></td>
                                <td>
                                    @if ($item->counterparty)
                                        <a href="{{ route('member.profile.show', $item->counterparty) }}">{{ $item->counterparty->name }}</a>
                                    @else
                                        {{ __('Withdrawn member') }}
                                    @endif
                                </td>
                                <td>
                                    {{-- A draft has no show page (opened via the edit form, write surface), so its
                                         subject is plain text until then. --}}
                                    @if ($showRoute)
                                        <a href="{{ route($showRoute, ['message' => $item->messageId]) }}">{{ $item->subject }}</a>
                                    @else
                                        {{ $item->subject }}
                                    @endif
                                </td>
                                <td>{{ \App\Support\LocalizedDate::dateTime($item->date) }}</td>
                            </tr>
                        @endforeach
                    </table>

                    {{ $messages->links() }}

                    <div class="operation">
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
        </div>
    </div>
@endsection
