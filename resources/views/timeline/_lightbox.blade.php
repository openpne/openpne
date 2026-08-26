{{-- One lightbox for the page: classic-timeline-dialogs.js sets the picture an attached-image link
     names and opens it. The link itself is the full-size file, so without the script it opens as
     a page, as OpenPNE 3's lightbox.js links did. --}}
<dialog class="timeline-lightbox" data-timeline-lightbox aria-label="{{ __('Image') }}">
    <img alt="">
    <form method="dialog"><button type="submit" class="btn">{{ __('Close') }}</button></form>
</dialog>
