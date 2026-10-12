/**
 * The global nav dropdown of the OpenPNE 3 theme shell, in place of its bootstrap-dropdown.js: a
 * plain click on the toggle opens or closes the menu, a click elsewhere or Escape closes it. Without
 * this script the menu stands open (the shell's noscript rule), so every item stays reachable.
 */
(function () {
    'use strict';

    /** False for a modified or non-primary click, which the browser answers itself. */
    function plainClick(event) {
        return !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button);
    }

    function setOpen(item, open) {
        item.classList.toggle('open', open);
        var toggle = item.querySelector('.dropdown-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    // `node --test` evaluates this file with a `module` in scope and takes the pure half alone.
    if (typeof module !== 'undefined') {
        module.exports = { setOpen: setOpen, plainClick: plainClick };

        return;
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var toggle = target ? target.closest('#globalNav li.dropdown > .dropdown-toggle') : null;
        if (toggle && plainClick(event)) {
            event.preventDefault();
            var item = toggle.parentNode;
            setOpen(item, !item.classList.contains('open'));

            return;
        }
        if (!target || !target.closest('#globalNav li.dropdown')) {
            document.querySelectorAll('#globalNav li.dropdown.open').forEach(function (item) {
                setOpen(item, false);
            });
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('#globalNav li.dropdown.open').forEach(function (item) {
                setOpen(item, false);
            });
        }
    });
})();
