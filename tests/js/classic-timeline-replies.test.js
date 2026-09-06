import '../../resources/js/components/compose/test-dom.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { beforeEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * The script is loaded the way the browser loads it (evaluated, not imported) with a `module` in
 * scope: that branch hands back the pure half and skips the DOM wiring.
 */
const load = (path) => {
    const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
    const script = { exports: {} };
    runInThisContext(`(function (module) {\n${source}\n})`, { filename: path })(script);

    return script.exports;
};

const { bodyLength, canSubmit, errorText, clearsBox } = load('public/js/classic-timeline-replies.js');
const compose = load('public/js/classic-timeline-compose.js');

const GRIN = '\u{1F600}';

test('a body is measured in code points over server-normalized newlines', () => {
    assert.equal(bodyLength(''), 0);
    assert.equal(bodyLength(GRIN), 1); // String.length would say 2
    assert.equal(bodyLength('a\r\nb'), 3);
    assert.equal(bodyLength('a\rb'), 3);
});

test('the two Classic compose paths count the same body the same way', () => {
    // One endpoint, one max:140: a rule that drifted here would refuse a body the box accepted.
    for (const value of ['', GRIN, 'a\r\nb', 'a\rb', `${GRIN}\r\n${GRIN}`, 'hi @Alice #tag']) {
        assert.equal(bodyLength(value), compose.bodyLength(value), JSON.stringify(value));
    }
});

test('an empty body and one past 140 code points cannot be submitted', () => {
    assert.equal(canSubmit(''), false);
    assert.equal(canSubmit('a'), true);
    assert.equal(canSubmit('a'.repeat(140)), true);
    assert.equal(canSubmit('a'.repeat(141)), false);
    assert.equal(canSubmit(GRIN.repeat(140)), true); // 280 UTF-16 units, 140 code points
});

test("a refusal shows the validator's line", () => {
    assert.equal(errorText(422, { errors: { body: ['本文は140文字以内'] } }, 'fallback'), '本文は140文字以内');
});

test('every other refusal shows the page\'s own words, never the framework message', () => {
    // 401 / 419 / 429 answer with an English literal whatever the site's language.
    assert.equal(errorText(419, { message: 'CSRF token mismatch.' }, 'fallback'), 'fallback');
    assert.equal(errorText(429, { message: 'Too Many Attempts.' }, 'fallback'), 'fallback');
    assert.equal(errorText(422, { message: 'The given data was invalid.' }, 'fallback'), 'fallback');
    assert.equal(errorText(500, null, 'fallback'), 'fallback');
    assert.equal(errorText(0, undefined, 'fallback'), 'fallback');
    // An `errors` payload on any other status is not the validator's.
    assert.equal(errorText(403, { errors: { body: ['leaked'] } }, 'fallback'), 'fallback');
});

test('a landed post empties the box only while it still holds what was sent', () => {
    assert.equal(clearsBox('hello', 'hello'), true);
    assert.equal(clearsBox('hello', 'hello and more'), false);
    assert.equal(clearsBox('hello', ''), false);
});

// --- the DOM half: evaluated without `module`, against a happy-dom document ---

const wire = (path) => {
    runInThisContext(readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8'), { filename: path });
};

window.fetch = () => new Promise(() => {});
// The controls as the Classic partials draw them: every one is a link with a real destination.
document.body.innerHTML = `
    <div id="notificationCenter">
        <a href="/notifications" class="ncbuttonLink" aria-expanded="false" aria-controls="notificationCenterDetail"
           data-notification-center-url="/notifications/center" data-notification-center-counts-url="/notifications/center/counts"><img alt=""></a>
        <div id="notificationCenterDetail">
            <div id="notificationCenterLoading"></div>
            <div id="notificationCenterError"></div>
        </div>
    </div>
    <div class="timeline-post" data-timeline-id="1">
        <dialog id="timeline-post-delete-confirm-1"><form method="post" action="/timeline/1/delete"><button type="submit">削除</button></form></dialog>
        <a href="/files/full.jpg" rel="lightbox"><div><img class="timeline-post-image" src="/files/thumb.jpg" alt=""></div></a>
        <div class="timeline-post-control">
            <a class="timeline-comment-link" href="/timeline/1#timeline-reply-form">コメントする</a>
            <a class="timeline-post-delete-confirm-link" href="/timeline/1/delete" data-dialog="timeline-post-delete-confirm-1">削除</a>
            <a class="timeline-comment-loadmore" href="/timeline/1" data-replies-url="/timeline/1/replies">もっと見る<span class="timeline-comment-loader"></span></a>
            <div class="timeline-post-comments">
                <form data-timeline-reply action="/timeline/1/reply">
                    <textarea class="timeline-post-comment-form-input"></textarea>
                    <button type="submit">投稿</button>
                </form>
            </div>
        </div>
    </div>
    <dialog data-timeline-lightbox><img src="" alt=""></dialog>
    <div class="parts line" id="backLink"><a href="/friend/list" data-history-back>前のページに戻る</a></div>
    <a class="reply" href="#formCommunityTopicComment" data-comment-reply="#comment_body" data-number="3" data-name="Alice">返信</a>
    <textarea id="comment_body"></textarea>`;
wire('public/js/classic-timeline-replies.js');
wire('public/js/classic-timeline-dialogs.js');
wire('public/js/classic-notification-center.js');
wire('public/js/classic-history-back.js');
wire('public/js/classic-comment-reply.js');
// The back line steps back only with history behind it, so give the document one entry to return to.
window.history.pushState({}, '', '/topics/1');

const bell = document.querySelector('#notificationCenter .ncbuttonLink');
const commentLink = document.querySelector('.timeline-comment-link');
const loadMore = document.querySelector('.timeline-comment-loadmore');
const deleteLink = document.querySelector('.timeline-post-delete-confirm-link');
const lightboxLink = document.querySelector('a[rel="lightbox"]');
const backLink = document.querySelector('a[data-history-back]');
const replyLink = document.querySelector('a[data-comment-reply]');
const commentBox = document.getElementById('comment_body');
const form = document.querySelector('[data-timeline-reply]');
const confirmDialog = document.getElementById('timeline-post-delete-confirm-1');
const lightbox = document.querySelector('dialog[data-timeline-lightbox]');

const click = (target, init) => {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(event);

    return event;
};

beforeEach(() => {
    bell.setAttribute('aria-expanded', 'false');
    form.classList.remove('comment-form-show');
    loadMore.removeAttribute('data-pending');
    loadMore.removeAttribute('data-failed');
    for (const dialog of [confirmDialog, lightbox]) {
        if (dialog.open) dialog.close();
    }
    lightbox.querySelector('img').src = '';
    commentBox.value = '';
});

test('a plain click on the back line steps the browser back in place', () => {
    assert.equal(click(backLink, {}).defaultPrevented, true);
});

test('a plain click on a comment Reply link quotes it into the box', () => {
    assert.equal(click(replyLink, {}).defaultPrevented, true);
    assert.equal(commentBox.value, '>>3 Alice\n');
});

test('a plain click on コメントする opens the box in place', () => {
    assert.equal(click(commentLink, {}).defaultPrevented, true);
    assert.equal(form.classList.contains('comment-form-show'), true);
});

test('a plain click on the load-more control is taken over', () => {
    assert.equal(click(loadMore, {}).defaultPrevented, true);
});

test('a plain click on 削除 opens the row\'s own confirmation', () => {
    assert.equal(click(deleteLink, {}).defaultPrevented, true);
    assert.equal(confirmDialog.open, true);
});

test('a plain click on an attached image opens the lightbox on the full-size file', () => {
    assert.equal(click(lightboxLink, {}).defaultPrevented, true);
    assert.equal(lightbox.open, true);
    assert.equal(lightbox.querySelector('img').getAttribute('src'), lightboxLink.href);
});

test('a plain click on the notification bell opens the panel in place', () => {
    assert.equal(click(bell, {}).defaultPrevented, true);
    assert.equal(bell.getAttribute('aria-expanded'), 'true');
});

test('a modified or non-primary click is left to the browser', () => {
    for (const init of [{ metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        const label = JSON.stringify(init);
        assert.equal(click(bell, init).defaultPrevented, false, label);
        assert.equal(bell.getAttribute('aria-expanded'), 'false', label);
        assert.equal(click(commentLink, init).defaultPrevented, false, label);
        assert.equal(form.classList.contains('comment-form-show'), false, label);
        assert.equal(click(loadMore, init).defaultPrevented, false, label);
        assert.equal(click(deleteLink, init).defaultPrevented, false, label);
        assert.equal(confirmDialog.open, false, label);
        assert.equal(click(lightboxLink, init).defaultPrevented, false, label);
        assert.equal(lightbox.open, false, label);
        assert.equal(click(backLink, init).defaultPrevented, false, label);
        assert.equal(click(replyLink, init).defaultPrevented, false, label);
        assert.equal(commentBox.value, '', label);
    }
});
