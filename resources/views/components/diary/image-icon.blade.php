{{-- OpenPNE 3 op_diary_image_icon: the camera marker following an entry's title when it carries
     photos. .imageIcon is an OpenPNE 4 styling hook, not an OpenPNE 3 class. --}}
@props(['count'])
@if ($count > 0) <img class="imageIcon" src="{{ asset('images/icon_camera.gif') }}" alt="{{ __('This entry has photos') }}">@endif
