@extends('layouts.classic')

@section('title', __('Pending members'))

@section('content')
    {{-- Join approvals, which OpenPNE 3 served from confirmation/list rather than per community, so
         the OpenPNE 3 id does not carry over. The roster shape does: _partsManageList.php is
         OpenPNE 3's per-member operation list (76×76 photo over the name, one operation per cell). --}}
    <x-classic.parts id="community_memberManage" name="manageList" :title="__('Pending members')">
        @if ($applicants->isEmpty())
            <p>{{ __('No pending requests.') }}</p>
        @else
            <x-classic.pager :paginator="$applicants" />
            <div class="item"><table><tbody>
                @foreach ($applicants as $applicant)
                    <tr>
                        <td class="photo"><a href="{{ route('member.profile.show', $applicant) }}"><x-classic.image :file="$applicant->avatar?->file" :size="76" :alt="$applicant->name" /></a><br /><a href="{{ route('member.profile.show', $applicant) }}">{{ $applicant->name }}</a></td>
                        <td>
                            <form method="POST" action="{{ route('group.members.approve', ['group' => $group->getKey()]) }}">
                                @csrf
                                <input type="hidden" name="member_id" value="{{ $applicant->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Approve') }}">
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('group.members.decline', ['group' => $group->getKey()]) }}">
                                @csrf
                                <input type="hidden" name="member_id" value="{{ $applicant->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Decline') }}">
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <x-classic.pager :paginator="$applicants" />
        @endif
    </x-classic.parts>
@endsection
