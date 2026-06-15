<x-gadget-part :part-id="$partId" :title="__('%Community%')">
    @if ($communities->isNotEmpty())
        <ul class="communityList">
            @foreach ($communities as $community)
                <li>
                    <a href="{{ route('community.show', $community) }}">
                        @if ($type !== 'only_name')
                            @php($image = $community->image)
                            @if ($image)
                                <img src="{{ $image->thumbnailUrl(76, 76, square: true) }}" alt="{{ $community->name }}">
                            @endif
                        @endif
                        @if ($type !== 'only_image')
                            <span class="communityName">{{ $community->name }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-gadget-part>
