/**
 * The @mention picker for the Classic compose forms: typing `@` opens a member list, and picking
 * one writes the handle into the body plus the hidden `mentions[]` rows that say where it landed.
 *
 * The state machine is the same contract Modern's resources/js/lib/mention-draft.ts states — both
 * surfaces post to one endpoint, so they must agree on when a mention survives an edit — restated
 * here rather than shared: this file ships unbuilt out of public/, and the picker's correctness is
 * pinned by test on each side (classic-timeline-mention.test.js).
 *
 * A draft entry holds its start as a UTF-16 offset, the unit the DOM reports a caret in, and is
 * converted to code points once, when the hidden rows are written over the value being sent.
 *
 * Every form is enhanced on its own — the OpenPNE 3 ids repeat when two home gadgets both render a
 * compose box — and nothing here is load-bearing: a browser that never runs it posts a plain body,
 * handles and all, as text.
 */
(function () {
    'use strict';

    /** Rows a post may carry; past this the picker stops offering (`mentions` max:10). */
    var MAX_MENTIONS = 10;

    /** Past this a search term is no longer a name being typed, so the trigger ends rather than widens. */
    var MAX_QUERY = 20;

    /** Long enough that typing a name is one search, short enough to feel like the list is following. */
    var SEARCH_DEBOUNCE_MS = 200;

    // A handle is a word: it starts the body or follows a space. `\s` covers the ideographic space a
    // Japanese keyboard produces, and the newline that keeps a trigger inside its own line.
    var SPACE = /\s/;

    /** Code points in `text`. `text.length` counts an astral emoji as two. */
    function codePointLength(text) {
        return Array.from(text).length;
    }

    /**
     * The trigger the caret is inside, or null. Scans back from the caret for the `@` that opens it,
     * so `a@b` (an address), a second `@` in `@@a`, and anything past a space or line break are not
     * one. Offsets are UTF-16: `start` is the `@`, `end` is the caret.
     */
    function detectTrigger(value, caret) {
        for (var i = caret - 1; i >= 0; i -= 1) {
            var char = value.charAt(i);
            if (SPACE.test(char)) {
                return null;
            }
            if (char !== '@') {
                continue;
            }
            if (i > 0 && !SPACE.test(value.charAt(i - 1))) {
                return null;
            }

            var query = value.slice(i + 1, caret);

            return codePointLength(query) > MAX_QUERY ? null : { start: i, end: caret, query: query };
        }

        return null;
    }

    /**
     * The candidates the picker may show and confirm: only ones searched for the query the caret is
     * in right now. Typing on past `@a` leaves that search's answer in hand until the next one
     * lands, and confirming it would attach a member who was never offered for what the field says.
     */
    function offeredCandidates(query, results) {
        return query !== null && results !== null && results.query === query ? results.items : [];
    }

    /**
     * Which keys the picker takes; null leaves the key to the field. Only an open list takes any —
     * Enter is a line break the rest of the time — and a converting IME takes none at all: the Enter
     * that commits a conversion would otherwise be read as a pick, and the arrows walk the IME's own
     * candidates.
     */
    function keyAction(key, state) {
        if (state.composing || !state.open) {
            return null;
        }
        switch (key) {
            case 'ArrowDown':
                return 'next';
            case 'ArrowUp':
                return 'previous';
            case 'Enter':
            case 'Tab':
                return 'confirm';
            case 'Escape':
                return 'dismiss';
            default:
                return null;
        }
    }

    /** Replace the trigger with the candidate's handle, and record the mention it just became. */
    function applyPick(mentions, value, trigger, candidate) {
        // The trailing space ends the trigger the caret is still in — without it the next keystroke
        // would reopen the picker on the name just chosen.
        var handle = '@' + candidate.name + ' ';

        return {
            value: value.slice(0, trigger.start) + handle + value.slice(trigger.end),
            mentions: carry(mentions, trigger.start, trigger.end, handle.length).concat([
                { memberId: candidate.id, label: candidate.name, start: trigger.start },
            ]),
            caret: trigger.start + handle.length,
        };
    }

    /**
     * Carry the draft across an edit, given the value before and after it.
     *
     * A pair of values does not name the span that changed: where the text repeats around the edit,
     * deleting the first `@Alice ` of two and deleting the second leave the very same pair behind.
     * So the edit is read twice, from each end, and a mention is carried only where both readings
     * say the same thing — see settle() for what is done with the rest.
     */
    function applyEdit(mentions, oldValue, newValue) {
        if (mentions.length === 0) {
            return mentions;
        }

        // What the two values share at either end. A textarea edit is a single contiguous change
        // (typing, deleting, pasting over a selection), so one span describes it — and where a scan
        // splits a surrogate pair, both halves sit inside one code point, which no mention boundary
        // ever falls inside.
        var limit = Math.min(oldValue.length, newValue.length);
        var prefix = 0;
        while (prefix < limit && oldValue.charCodeAt(prefix) === newValue.charCodeAt(prefix)) {
            prefix += 1;
        }
        var suffix = 0;
        while (suffix < limit && oldValue.charCodeAt(oldValue.length - 1 - suffix) === newValue.charCodeAt(newValue.length - 1 - suffix)) {
            suffix += 1;
        }

        // The two ends the shared runs may be split at: the rightmost span the prefix allows, and
        // the leftmost one the suffix allows. They coincide unless the runs overlap, which is
        // exactly when the edit is ambiguous.
        var left = read(mentions, oldValue, newValue, prefix, Math.min(suffix, limit - prefix));
        var right = read(mentions, oldValue, newValue, Math.min(prefix, limit - suffix), suffix);

        var carried = [];
        for (var i = 0; i < mentions.length; i += 1) {
            var start = settle(left[i], right[i]);
            if (start !== null) {
                carried.push({ memberId: mentions[i].memberId, label: mentions[i].label, start: start });
            }
        }

        return carried;
    }

    /** Where each mention lands under one reading of the edit. */
    function read(mentions, oldValue, newValue, start, suffix) {
        var landed = [];
        for (var i = 0; i < mentions.length; i += 1) {
            landed.push(carryOne(mentions[i], start, oldValue.length - suffix, newValue.length - suffix - start));
        }

        return landed;
    }

    /**
     * The start both readings support, or null to give the mention up.
     *
     * Where they disagree the draft may not guess — not even for a label no other entry carries: the
     * body may hold the same `@name` as hand-typed plain text (the feature's own contract), and the
     * guess would promote it to a mention of the member the writer just deleted. Nothing downstream
     * can catch it — toPayload() and the server both re-read the same `@name` text. Losing a mention
     * to plain text is the honest failure; inventing one is not.
     */
    function settle(left, right) {
        return left === right ? left : null;
    }

    /**
     * Where a mention lands once `[start, end)` has become `inserted` code units, or null if the
     * edit reached into it — only text the member picked may stay a mention, and they just rewrote
     * part of it. A mention before the edit is untouched, one after it moves.
     */
    function carryOne(mention, start, end, inserted) {
        if (mention.start + 1 + mention.label.length <= start) {
            return mention.start;
        }
        if (mention.start >= end) {
            return mention.start + inserted - (end - start);
        }

        return null;
    }

    /** The draft after `[start, end)` became `inserted` code units. */
    function carry(mentions, start, end, inserted) {
        var carried = [];
        for (var i = 0; i < mentions.length; i += 1) {
            var landed = carryOne(mentions[i], start, end, inserted);
            if (landed !== null) {
                carried.push({ memberId: mentions[i].memberId, label: mentions[i].label, start: landed });
            }
        }

        return carried;
    }

    /**
     * The rows to submit with `value`, ascending by offset, in code points. Each is re-read off the
     * body first: a draft entry survives only while the text it covers still reads as the handle the
     * picker wrote, the same shape the server re-checks against the member's current name.
     */
    function toPayload(mentions, value) {
        var rows = [];
        for (var i = 0; i < mentions.length; i += 1) {
            var mention = mentions[i];
            if (value.slice(mention.start, mention.start + 1 + mention.label.length) !== '@' + mention.label) {
                continue;
            }
            rows.push({
                member_id: mention.memberId,
                offset: codePointLength(value.slice(0, mention.start)),
                length: 1 + codePointLength(mention.label),
            });
        }

        return rows.sort(function (a, b) {
            return a.offset - b.offset;
        });
    }

    /**
     * toPayload's inverse, for the draft a failed validation flashed back: re-anchor each stored
     * code-point row onto the restored body and resume edit tracking as if it was never submitted.
     * A row the body no longer carries — or one that was never a well-formed row — is dropped;
     * the server re-validates whatever is resubmitted, so nothing here is trusted for more than
     * picking up where the writer left off.
     */
    function fromPayload(rows, value) {
        var points = Array.from(value);
        var mentions = [];
        for (var i = 0; i < rows.length; i += 1) {
            var memberId = Number(rows[i].member_id);
            var offset = Number(rows[i].offset);
            var length = Number(rows[i].length);
            if (!isFinite(memberId) || memberId <= 0 || !isFinite(offset) || offset < 0 || !isFinite(length) || length < 2 || offset + length > points.length) {
                continue;
            }
            var handle = points.slice(offset, offset + length).join('');
            if (handle.charAt(0) !== '@') {
                continue;
            }
            mentions.push({
                memberId: memberId,
                label: handle.slice(1),
                start: points.slice(0, offset).join('').length,
            });
        }

        return mentions.sort(function (a, b) {
            return a.start - b.start;
        });
    }

    var api = {
        MAX_MENTIONS: MAX_MENTIONS,
        detectTrigger: detectTrigger,
        offeredCandidates: offeredCandidates,
        keyAction: keyAction,
        applyPick: applyPick,
        applyEdit: applyEdit,
        toPayload: toPayload,
        fromPayload: fromPayload,
    };

    // The browser goes on to the wiring below. `node --test` evaluates this same file as a classic
    // script with a `module` in scope and takes the state machine alone, which is the half worth
    // pinning by test.
    if (typeof module !== 'undefined') {
        module.exports = api;

        return;
    }

    /** Distinguishes the listboxes when a page carries more than one compose form. */
    var listSeq = 0;

    /** The flashed draft rows a failed validation re-rendered (timeline/_mention-draft.blade.php). */
    function readRows(form) {
        var inputs = form.querySelectorAll('input[type="hidden"][data-mention]');
        var rows = {};
        for (var i = 0; i < inputs.length; i += 1) {
            var match = /^mentions\[(\d+)\]\[(member_id|offset|length)\]$/.exec(inputs[i].name);
            if (match) {
                (rows[match[1]] = rows[match[1]] || {})[match[2]] = inputs[i].value;
            }
        }

        return Object.keys(rows).map(function (key) {
            return rows[key];
        });
    }

    function setUp(form) {
        var textarea = form.querySelector('textarea[name="body"]');
        var url = form.getAttribute('data-mention-candidates-url');
        if (!textarea || !url) {
            return;
        }

        var mentions = fromPayload(readRows(form), textarea.value);
        var value = textarea.value;
        var trigger = null;
        // Kept with the query it answers, so a search the field has already typed past shows nothing
        // and confirms nothing while the next one is still out (offeredCandidates).
        var results = null;
        var searching = null;
        var active = 0;
        // Escape gives up on the trigger the caret is in, not on the picker: detecting no trigger at
        // all ends the refusal, so deleting the `@` and typing it again offers the list once more.
        var dismissed = false;
        // An IME is converting: the half-formed reading under the caret is not a search term.
        var composing = false;
        var timer = null;
        var request = 0;

        listSeq += 1;
        var listId = 'classicMentionList' + listSeq;
        var list = document.createElement('div');
        list.id = listId;
        list.className = 'classicMentionList';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', form.getAttribute('data-mention-label') || '');
        list.hidden = true;
        textarea.parentNode.insertBefore(list, textarea.nextSibling);

        // A textbox with a popup, not a combobox: ARIA-in-HTML permits no role on <textarea>, and
        // taking `combobox` would trade the field's multiline semantics for an expanded state that
        // aria-activedescendant already conveys as it moves.
        textarea.setAttribute('aria-haspopup', 'listbox');
        textarea.setAttribute('aria-controls', listId);
        textarea.setAttribute('aria-autocomplete', 'list');

        function query() {
            return trigger !== null && !dismissed && mentions.length < MAX_MENTIONS ? trigger.query : null;
        }

        function candidates() {
            return offeredCandidates(query(), results);
        }

        // The surface's own thumbnail idiom (x-classic.image): the photo, or OpenPNE 3's
        // no_image.gif — never Modern's initial badge.
        function avatar(candidate) {
            var image = document.createElement('img');
            image.className = 'classicMentionAvatar';
            image.src = candidate.imageUrl || form.getAttribute('data-mention-no-image-url');
            image.width = 24;
            image.height = 24;
            image.alt = ''; // the name beside it already names the row
            return image;
        }

        function option(candidate, index) {
            var row = document.createElement('div');
            row.id = listId + '-' + index;
            row.className = 'classicMentionOption';
            row.setAttribute('role', 'option');
            row.appendChild(avatar(candidate));
            var name = document.createElement('span');
            name.className = 'classicMentionName';
            name.textContent = candidate.name;
            row.appendChild(name);
            // Keep the caret where it is: the blur would close the list before the click landed.
            row.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            row.addEventListener('click', function () {
                pick(candidate);
            });
            row.addEventListener('mouseover', function () {
                active = index;
                highlight();
            });
            return row;
        }

        /** Move the selection without rebuilding the rows the pointer may be over. */
        function highlight() {
            for (var i = 0; i < list.children.length; i += 1) {
                var selected = i === active;
                list.children[i].className = 'classicMentionOption' + (selected ? ' classicMentionOptionActive' : '');
                list.children[i].setAttribute('aria-selected', selected ? 'true' : 'false');
            }
            textarea.setAttribute('aria-activedescendant', listId + '-' + active);
        }

        function render() {
            var items = candidates();
            while (list.firstChild) {
                list.removeChild(list.firstChild);
            }
            if (items.length === 0) {
                list.hidden = true;
                textarea.removeAttribute('aria-activedescendant');
                return;
            }
            if (active >= items.length) {
                active = 0;
            }
            for (var i = 0; i < items.length; i += 1) {
                list.appendChild(option(items[i], i));
            }
            list.hidden = false;
            highlight();
        }

        /** Start (or drop) the search for the term the caret is in now. */
        function search() {
            var term = query();
            if (term === searching) {
                return; // the same term: the search already out answers it
            }
            searching = term;
            results = null; // the answer to the previous term is not an answer to this one
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
            if (term === null) {
                return;
            }
            timer = setTimeout(function () {
                fetchCandidates(term);
            }, SEARCH_DEBOUNCE_MS);
        }

        function fetchCandidates(term) {
            timer = null;
            request += 1;
            var mine = request;

            window
                .fetch(url + '?q=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                })
                .then(function (response) {
                    // A session that expired answers with the login page; only ever read the JSON
                    // this endpoint serves.
                    var type = response.headers.get('Content-Type') || '';
                    if (!response.ok || response.redirected || type.indexOf('application/json') !== 0) {
                        throw new Error('unexpected response');
                    }
                    return response.json();
                })
                .then(function (body) {
                    if (mine !== request) {
                        return; // a later search has already been asked
                    }
                    results = { query: term, items: body && body.candidates ? body.candidates : [] };
                    active = 0;
                    render();
                })
                .catch(function () {
                    // A refused or failed search closes the picker and says nothing: the member is
                    // writing a message, and an error about a decoration would interrupt that.
                    if (mine === request) {
                        results = { query: term, items: [] };
                        render();
                    }
                });
        }

        function syncTrigger() {
            if (composing) {
                return;
            }
            trigger = detectTrigger(textarea.value, textarea.selectionStart);
            if (trigger === null) {
                dismissed = false;
            }
            search();
            render();
        }

        /** Rewrite the rows the form submits, over the body as it reads right now. */
        function writeRows() {
            var stale = form.querySelectorAll('input[type="hidden"][data-mention]');
            for (var i = 0; i < stale.length; i += 1) {
                stale[i].parentNode.removeChild(stale[i]);
            }
            var rows = toPayload(mentions, textarea.value);
            for (var j = 0; j < rows.length; j += 1) {
                appendRow('mentions[' + j + '][member_id]', rows[j].member_id);
                appendRow('mentions[' + j + '][offset]', rows[j].offset);
                appendRow('mentions[' + j + '][length]', rows[j].length);
            }
        }

        function appendRow(name, fieldValue) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(fieldValue);
            input.setAttribute('data-mention', '');
            form.appendChild(input);
        }

        function pick(candidate) {
            if (!candidate || trigger === null) {
                return;
            }
            var result = applyPick(mentions, textarea.value, trigger, candidate);
            mentions = result.mentions;
            value = result.value;
            textarea.value = result.value;
            textarea.focus();
            textarea.setSelectionRange(result.caret, result.caret);
            trigger = null;
            dismissed = false;
            // The inline box's counter and submit gate listen for `input`
            // (classic-timeline-compose.js); the handler below rewrites the rows and closes the list.
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function close() {
            trigger = null;
            dismissed = false;
            search();
            render();
        }

        textarea.addEventListener('input', function () {
            mentions = applyEdit(mentions, value, textarea.value);
            value = textarea.value;
            writeRows();
            syncTrigger();
        });

        textarea.addEventListener('keydown', function (event) {
            var items = candidates();
            // Both flags: `isComposing` is unset on the keydown that follows a commit in some
            // browsers, while the local one spans the whole compositionstart–end window.
            var action = keyAction(event.key, { open: items.length > 0, composing: composing || event.isComposing });
            if (action === null) {
                return;
            }
            event.preventDefault();
            if (action === 'confirm') {
                pick(items[active]);
            } else if (action === 'dismiss') {
                dismissed = true;
                search();
                render();
            } else {
                active = (active + (action === 'next' ? 1 : items.length - 1)) % items.length;
                highlight();
            }
        });

        // The caret can move without the value changing, and a trigger is where the caret is.
        textarea.addEventListener('keyup', syncTrigger);
        textarea.addEventListener('click', syncTrigger);
        textarea.addEventListener('blur', close);
        textarea.addEventListener('compositionstart', function () {
            composing = true;
        });
        textarea.addEventListener('compositionend', function () {
            composing = false;
            syncTrigger();
        });

        // Normalize whatever the flashed draft re-rendered: surviving rows are rewritten in the
        // script's own shape, garbage ones leave the form.
        writeRows();
    }

    if (window.fetch) {
        var forms = document.querySelectorAll('[data-timeline-mention]');
        for (var i = 0; i < forms.length; i += 1) {
            setUp(forms[i]);
        }
    }
})();
