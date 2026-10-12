{{-- Claimed once per page, and ahead of the themed navbar list, where a <style> or <script> cannot sit as an item. --}}
@once('classic-notification-center-style')
    <style>
        .ncbuttonLink { text-decoration: none; }
        .notificationCenterRowLink { padding: 0; border: 0; background: none; font: inherit; color: inherit; text-align: left; cursor: pointer; }
    </style>
@endonce
@once('classic-notification-center-script')
    <script src="{{ asset('js/classic-notification-center.js') }}" defer></script>
@endonce
