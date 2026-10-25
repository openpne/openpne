{{-- A post's non-picture attachments as download links; the <ul class="files"> markup is the CSS
     seam beside ul.photo. $images = the post's slot rows (every kind), number-ordered. --}}
@php($files = \App\Files\PostAttachments::partition($images)['files'])
@if ($files->isNotEmpty())
    <ul class="files">
        @foreach ($files as $attachment)
            <li><a href="{{ $attachment->file->url() }}">{{ $attachment->file->original_filename ?? __('File') }}</a> ({{ \Illuminate\Support\Number::fileSize((int) $attachment->file->byte_size, precision: 1) }})</li>
        @endforeach
    </ul>
@endif
