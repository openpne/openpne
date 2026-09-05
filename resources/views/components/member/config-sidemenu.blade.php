{{-- The settings categories, the current one rendered as plain text, the rest linked to
     ?category=. The privacy category is offered only while it has something to set (an age to
     gate, or a profile-page choice the policy allows), the diary category only while the unit is
     switched on, and the AI category to a member the site offers them to or who already owns one. --}}
@props(['current' => null, 'publicFlagAvailable' => null, 'aiAvailable' => false])
@php($publicFlagAvailable ??= \App\Features\Profile\ProfilePageVisibility::privacyCategoryAvailable())
<x-classic.parts id="pageNav" name="pageNav">
    <ul>
        @foreach (\App\Features\Member\MemberConfigCategory::cases() as $category)
            @continue($category === \App\Features\Member\MemberConfigCategory::PublicFlag && ! $publicFlagAvailable)
            @continue($category === \App\Features\Member\MemberConfigCategory::Diary && ! \App\Support\Feature::Diary->enabled())
            @continue($category === \App\Features\Member\MemberConfigCategory::Ai && ! $aiAvailable)
            <li @class(['current' => $current === $category])>
                @if ($current === $category)
                    {{ $category->caption() }}
                @else
                    <a href="{{ route('member.config', ['category' => $category->value]) }}">{{ $category->caption() }}</a>
                @endif
            </li>
        @endforeach
    </ul>
</x-classic.parts>
