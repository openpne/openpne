@extends('layouts.classic')

@section('title', __('Profile image'))

@section('content')
    {{-- OpenPNE 3 configImageSuccess.php: the memberImagesBox kind under the memberImageUploadBox id.
         One avatar here where OpenPNE 3 held three photos, so the kind's photo table is a single
         cell. --}}
    <x-classic.parts id="memberImageUploadBox" name="memberImagesBox" :title="__('Profile image')">
        <table>
            <tr>
                <td><x-classic.image :file="$avatar" :size="120" :alt="__('Profile image')" /></td>
            </tr>
        </table>
        <div class="block">
            <form method="POST" action="{{ route('member.avatar.update') }}" enctype="multipart/form-data">
                @csrf
                <input type="file" class="input_file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Upload') }}"></li>
                    </ul>
                </div>
            </form>
            @if ($avatar)
                <form method="POST" action="{{ route('member.avatar.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Remove') }}"></li>
                        </ul>
                    </div>
                </form>
            @endif
        </div>
    </x-classic.parts>
@endsection
