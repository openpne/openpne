<x-filament-panels::page>
    @php($surface = $this->surface())

    <x-filament::section>
        <x-slot name="heading">{{ __('What members see') }}</x-slot>

        <p>
            @if ($surface['modernOnly'])
                {{ __('Members see the Modern view on canonical URLs (modern_only mode).') }}
            @elseif ($surface['default'] === 'modern')
                {{ __('By default members see the Modern view.') }}
            @else
                {{ __('By default members see the Classic view.') }}
            @endif
        </p>

        @unless ($surface['modernOnly'])
            <p>{{ __('Members can switch between Classic and Modern in their own settings, unless a page is pinned to one surface.') }}</p>
        @endunless
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('Classic and Modern') }}</x-slot>

        <p>{{ __('Classic is the OpenPNE 3-compatible view; Modern is the new interface.') }}</p>
        <p>{{ __('The appearance settings in this section affect the Classic view. Modern has its own design settings.') }}</p>
    </x-filament::section>

    <p class="fi-text-sm">
        {{ __('These values come from the site configuration and are shown here for reference.') }}
    </p>
</x-filament-panels::page>
