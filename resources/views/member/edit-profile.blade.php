@extends('layouts.classic')

@section('title', __('Edit Profile'))

@section('content')
    <div class="dparts form" id="member_editProfile">
        <div class="partsHeading"><h3>{{ __('Edit Profile') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('member.profile.update') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="member_name">{{ __('%nickname%') }}</label></th>
                        <td>
                            <input type="text" class="input_text" id="member_name" name="name" value="{{ old('name', $member->name) }}" maxlength="255" required>
                            @error('name')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>

                    @include('profile._fields')
                </table>

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Update') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
