{{-- A locale switcher (POST /locale), wrapped in a box for the side banner. --}}
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
