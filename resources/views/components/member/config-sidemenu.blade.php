{{-- The settings categories, the current one rendered as plain text, the rest linked to
     ?category=. The age category is offered only while the site has a birthday profile item
     (no birthday → no age to gate). --}}
@props(['current' => null, 'ageAvailable' => true])
<x-classic.parts id="pageNav" name="pageNav">
    <ul>
        @foreach (\App\Features\Member\MemberConfigCategory::cases() as $category)
            @continue($category === \App\Features\Member\MemberConfigCategory::PublicFlag && ! $ageAvailable)
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
