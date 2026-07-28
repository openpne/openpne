@extends('layouts.classic')

@section('title', __('Profile image'))

@section('content')
    {{-- OpenPNE 3 configImageSuccess.php: the memberImagesBox kind under the memberImageUploadBox id.
         One avatar here where OpenPNE 3 held three photos, so the kind's photo table is a single
         cell. --}}
    <x-classic.parts id="memberImageUploadBox" name="memberImagesBox" :title="__('Profile image')">
        <table>
            <tr>
                <td>
                    <x-classic.image :file="$avatar" :size="120" :alt="__('Profile image')" />
                    @if ($avatar)
                        {{-- OpenPNE 3 removes a photo from under the photo itself. Beside the
                             uploader it would take a second floated column and squeeze the notes. --}}
                        <br>
                        <form method="POST" action="{{ route('member.avatar.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <input type="submit" class="input_submit" value="{{ __('Remove') }}">
                        </form>
                    @endif
                </td>
            </tr>
        </table>
        <div class="block">
            {{-- The kind's stylesheet floats this form into a fixed column and indents the notes ul
                 past it, so the button takes OpenPNE 3's `form > p`: an .operation ul picks up that
                 indent and lands on the notes. --}}
            <form method="POST" action="{{ route('member.avatar.update') }}" enctype="multipart/form-data">
                @csrf
                <p><input type="file" class="input_file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required></p>
                <p><input type="submit" class="input_submit" value="{{ __('Upload') }}"></p>
            </form>
            {{-- The memberImagesBox kind's upload notes. OpenPNE 3's third note capped the member at
                 three photos; OpenPNE 4 holds one avatar, so it has nothing to say here. --}}
            <ul>
                <li>{{ __('Please upload a GIF, JPEG or PNG within :max_size bytes.', ['max_size' => $maxUploadBytes]) }}</li>
                <li>{{ __('Photos that infringe copyright or portrait rights, violent or obscene photos, and photos other members would find offensive are prohibited. Post them at your own responsibility.') }}</li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
