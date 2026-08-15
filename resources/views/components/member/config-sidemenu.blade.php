{{-- The settings categories, the current one rendered as plain text, the rest linked to
     ?category=. The age category is offered only while the site has a birthday profile item
     (no birthday → no age to gate), the diary category only while the unit is switched on, and the
     AI category to a member the site offers them to or who already owns one. --}}
@props(['current' => null, 'ageAvailable' => true, 'aiAvailable' => false])
<x-classic.parts id="pageNav" name="pageNav">
    <ul>
        @foreach (\App\Features\Member\MemberConfigCategory::cases() as $category)
            @continue($category === \App\Features\Member\MemberConfigCategory::PublicFlag && ! $ageAvailable)
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
