<x-gadget-part :part-id="$partId" :title="__('%Friends%')">
    @if ($friends->isNotEmpty())
        <ul class="friendList">
            @foreach ($friends as $friend)
                <li>
                    <a href="{{ route('member.profile.show', $friend) }}">
                        @if ($type !== 'only_name')
                            @php($avatar = $friend->avatar?->file)
                            @if ($avatar)
                                <img src="{{ $avatar->thumbnailUrl(76, 76, square: true) }}" alt="{{ $friend->name }}">
                            @endif
                        @endif
                        @if ($type !== 'only_image')
                            <span class="memberName">{{ $friend->name }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-gadget-part>
