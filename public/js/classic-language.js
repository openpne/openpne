/**
 * The Classic language selector: OpenPNE 3 submitted the form the moment the choice changed
 * (an onchange submit), and this restores that. One delegated listener serves every instance.
 */
(function () {
    'use strict';

    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!select || select.name !== 'locale') {
            return;
        }
        var form = select.closest ? select.closest('form[data-language-switch]') : null;
        if (form) {
            form.submit();
        }
    });
})();
