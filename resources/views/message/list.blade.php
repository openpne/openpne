@extends('layouts.classic')

@section('title', $box->heading())

@section('sidemenu')
    <x-message.sidemenu :current="$box" />
@endsection

@section('content')
    @php($showRoute = $box->showRoute())
    <div class="dparts searchResultList" id="message_list">
        <div class="parts">
            <div class="partsHeading"><h3>{{ $box->heading() }}</h3></div>
            @if ($messages->isEmpty())
                <div class="body">{{ __('There are no messages') }}</div>
            @else
                <table>
                    <tr>
                        <th></th>
                        <th>{{ $box->counterpartyHeading() }}</th>
                        <th>{{ __('Subject') }}</th>
                        <th>{{ __('Created At') }}</th>
                    </tr>
                    @foreach ($messages as $item)
                        {{-- OpenPNE 3 marks an unread received row with class="unread". --}}
                        <tr @class(['unread' => $item->unread])>
                            <td class="status"><span @class(['read' => ! $item->unread, 'unread' => $item->unread])></span></td>
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
            @endif
        </div>
    </div>
@endsection
