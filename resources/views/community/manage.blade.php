@extends('layouts.classic')

@section('title', __('Management member'))

@section('content')
    <div class="dparts" id="community_memberManage">
        <div class="partsHeading"><h3>{{ __('Management member') }}</h3></div>
        <div class="parts">
            <table>
                @foreach ($members as $membership)
                    @php($rowMember = $membership->member)
                    <tr>
                        <td><a href="{{ route('member.profile.show', $rowMember) }}">{{ $rowMember->name }}</a></td>

                        {{-- Drop cell (admin and sub-admin viewers): only plain-member rows are droppable
                             (admin/sub-admin rows, and therefore self, are never dropped — OpenPNE 3 parity). --}}
                        <td>
                            @if ($membership->role === \App\Features\Community\CommunityRole::Member)
                                <a href="{{ route('community.members.drop.show', ['id' => $community->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __('Drop this member') }}</a>
                            @endif
                        </td>

                        {{-- Sub-admin cell (admin viewer only): appoint a plain member, demote a sub-admin.
                             The pending transfer nominee cannot be appointed while the transfer is open. --}}
                        @if ($viewerRole === \App\Features\Community\CommunityRole::Admin)
                            <td>
                                @if ($membership->role === \App\Features\Community\CommunityRole::Member && (int) $rowMember->getKey() !== (int) $pendingAdminId)
                                    <a href="{{ route('community.members.appoint.show', ['id' => $community->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __("Appoint this member as this %community%'s sub-administrator") }}</a>
                                @elseif ($membership->role === \App\Features\Community\CommunityRole::SubAdmin)
                                    <a href="{{ route('community.members.demote.show', ['id' => $community->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __("Demote this member from this %community%'s sub-administrator") }}</a>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>

            {{ $members->links() }}
        </div>
    </div>
@endsection
