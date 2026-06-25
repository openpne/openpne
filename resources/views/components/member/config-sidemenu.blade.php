{{-- OpenPNE 3 member/config category pageNav (configSuccess op_sidemenu): the settings categories,
     the current one rendered as plain text, the rest linked to ?category=. --}}
@props(['current' => null])
<div class="parts pageNav">
    <ul>
        @foreach (\App\Features\Member\MemberConfigCategory::cases() as $category)
            <li @class(['current' => $current === $category])>
                @if ($current === $category)
                    {{ $category->caption() }}
                @else
                    <a href="{{ route('member.config', ['category' => $category->value]) }}">{{ $category->caption() }}</a>
                @endif
            </li>
        @endforeach
    </ul>
</div>
