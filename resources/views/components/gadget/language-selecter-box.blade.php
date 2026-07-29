{{-- OpenPNE 3 languageSelecterBox (_languageSelecterBox.php): a bare form — label, colon, the
     select that submits itself on change. No box, no heading, no button; without the script the
     control is as inert as OpenPNE 3's own was without JavaScript. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<form method="POST" action="{{ route('locale.switch') }}" data-language-switch>
    @csrf
    <label for="language_culture">{{ __('Language') }}</label>:
    <select name="locale" id="language_culture">
        <option value="ja" @selected(app()->getLocale() === 'ja')>日本語</option>
        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
    </select>
</form>
@once
    <script src="{{ asset('js/classic-language.js') }}" defer></script>
@endonce
