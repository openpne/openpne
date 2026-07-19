{{-- One caution line per community awaiting the viewer's admin-transfer decision, each linking to
     that community's home where the accept/reject banner lives (OpenPNE 3 _cautionAboutChangeAdminRequest,
     which pointed at the removed confirmation center). Renders nothing when there is none. --}}
@foreach ($adminTransferCommunities ?? [] as $nominatingCommunity)
    <p class="caution">
        {{ __('The administrator of :name asks you to take over the administration.', ['name' => $nominatingCommunity->name]) }}
        <a href="{{ route('community.show', $nominatingCommunity) }}">{{ $nominatingCommunity->name }}</a>
    </p>
@endforeach
