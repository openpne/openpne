@extends('layouts.classic')

@section('title', __('Management member'))

@section('content')
    {{-- OpenPNE 3 memberManageSuccess.php hand-writes this box as a lone .parts with no kind and no
         id, and wraps the roster table in a div.item; the id here is OpenPNE 4's own. --}}
    <x-classic.parts id="community_memberManage" :single="true" :title="__('Management member')">
        <x-classic.pager :paginator="$members" />
        <div class="item">
            <table>
                @foreach ($members as $membership)
                    @php($rowMember = $membership->member)
                    <tr>
                        <td class="member"><a href="{{ route('member.profile.show', $rowMember) }}">{{ $rowMember->name }}</a></td>

                        {{-- Drop cell (admin and sub-admin viewers): only plain-member rows are droppable
                             (admin/sub-admin rows, and therefore self, are never dropped — OpenPNE 3 parity). --}}
                        <td class="drop">
                            @if ($membership->role === \App\Features\Group\GroupRole::Member)
                                <a href="{{ route('group.members.drop.show', ['group' => $group->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __('Drop this member') }}</a>
                            @else
                                &nbsp;
                            @endif
                        </td>

                        {{-- Sub-admin cell (admin viewer only): appoint a plain member, demote a sub-admin.
                             The pending transfer nominee cannot be appointed while the transfer is open.
                             OpenPNE 3's "requesting sub-administrator now" status has no counterpart —
                             appointment here takes effect on confirm rather than waiting on the member. --}}
                        @if ($viewerRole === \App\Features\Group\GroupRole::Admin)
                            <td class="sub_admin_request">
                                @if ($membership->role === \App\Features\Group\GroupRole::Member && (int) $rowMember->getKey() !== (int) $pendingAdminId)
                                    <a href="{{ route('group.members.appoint.show', ['group' => $group->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __("Appoint this member as this %community%'s sub-administrator") }}</a>
                                @elseif ($membership->role === \App\Features\Group\GroupRole::SubAdmin)
                                    <a href="{{ route('group.members.demote.show', ['group' => $group->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __("Demote this member from this %community%'s sub-administrator") }}</a>
                                @else
                                    &nbsp;
                                @endif
                            </td>

                            {{-- Admin-transfer cell (admin viewer only): the pending nominee shows a status,
                                 the admin row is blank, every other row offers the take-over request. A pending
                                 transfer does not freeze other rows' links — a new request replaces the old
                                 nominee (OpenPNE 3 parity). --}}
                            <td class="admin_request">
                                @if ((int) $rowMember->getKey() === (int) $pendingAdminId)
                                    {{ __("You are taking over this %community%'s administrator to this member now.") }}
                                @elseif ($membership->role !== \App\Features\Group\GroupRole::Admin)
                                    <a href="{{ route('group.members.transfer.show', ['group' => $group->getKey(), 'member_id' => $rowMember->getKey()]) }}">{{ __("Take over this %community%'s administrator to this member") }}</a>
                                @else
                                    &nbsp;
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
        <x-classic.pager :paginator="$members" />
    </x-classic.parts>
@endsection
