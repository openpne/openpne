{{-- OpenPNE 3 default/languageSelecterBox: a locale switcher. Functional equivalent of OpenPNE 4
     locale switching (POST /locale), not a byte-for-byte template port; wrapped in a box for the
     side banner since OpenPNE 3 emitted no parts wrapper here. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" part-name="box" :title="__('Language')">
    <div class="body">
        <form method="POST" action="{{ route('locale.switch') }}">
            @csrf
            <select name="locale" aria-label="{{ __('Language') }}">
                <option value="ja" @selected(app()->getLocale() === 'ja')>日本語</option>
                <option value="en" @selected(app()->getLocale() === 'en')>English</option>
            </select>
            <button type="submit" class="input_submit">{{ __('Change') }}</button>
        </form>
    </div>
</x-gadget-part>
