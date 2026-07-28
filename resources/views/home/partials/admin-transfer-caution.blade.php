{{-- One caution line per community awaiting the viewer's admin-transfer decision, each linking to
     that community's home where the accept/reject banner lives (OpenPNE 3 _cautionAboutChangeAdminRequest,
     which pointed at the removed confirmation center). OpenPNE 3 hung every caution off the single
     informationBox body, so one box wraps the whole set. Renders nothing when there is none. --}}
@if (count($adminTransferCommunities ?? []))
    <x-classic.parts name="informationBox">
        <div class="body">
            @foreach ($adminTransferCommunities as $nominatingCommunity)
                <p class="caution">
                    {{ __('The administrator of :name asks you to take over the administration.', ['name' => $nominatingCommunity->name]) }}
                    <a href="{{ route('community.show', $nominatingCommunity) }}">{{ $nominatingCommunity->name }}</a>
                </p>
            @endforeach
        </div>
    </x-classic.parts>
@endif
