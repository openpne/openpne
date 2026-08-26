{{-- One row's whole reply list, served as a fragment when the reader asks for the earlier comments.
     The rows the page would have drawn, and nothing around them: the script replaces the list it has
     with this one. --}}
@foreach ($replies as $reply)
    @include('timeline._reply', ['reply' => $reply])
@endforeach
