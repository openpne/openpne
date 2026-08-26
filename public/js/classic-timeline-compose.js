/*
 * The OpenPNE 3 inline compose behavior for the Classic timeline (_timelineAll's script,
 * server-rendered form): reveal the form, retire its no-JS fallback link, expand on focus,
 * count down from 140, gate the submit, and proxy the offscreen file input through the camera
 * button. Every lookup is scoped to one form — the OpenPNE 3 ids repeat when two home gadgets
 * both render a compose box — and the reveal comes last, so a failure anywhere leaves the
 * hidden form and the working link.
 */
(function () {
  'use strict';

  var MAXLENGTH = 140;

  // Code points, over the body with its newlines normalized the way the server normalizes them
  // (StoreTimelinePostRequest) — so this counter, the server's max:140 and the mention offsets all
  // measure the same thing. String.length would count an astral emoji as two.
  function bodyLength(value) {
    return Array.from(value.replace(/\r\n?/g, '\n')).length;
  }

  // `node --test` evaluates this file with a `module` in scope and takes the pure half alone —
  // here, so the inline reply layer's copy of this rule can be pinned against it.
  if (typeof module !== 'undefined') {
    module.exports = { bodyLength: bodyLength };

    return;
  }

  function setUp(form) {
    var textarea = form.querySelector('#timeline-textarea');
    var area = form.querySelector('#timeline-submit-area');
    var counter = form.querySelector('#counter');
    var submit = form.querySelector('#timeline-submit-button');
    var photoButton = form.querySelector('#timeline-upload-photo-button');
    var fileInput = form.querySelector('#timeline-submit-upload');
    var fileName = form.querySelector('#photo-file-name');
    var photoRemove = form.querySelector('#photo-remove');
    var postform = form.querySelector('.timeline-postform');
    if (!textarea || !area || !counter || !submit || !fileInput || !postform) {
      return false;
    }

    function sync() {
      var remaining = MAXLENGTH - bodyLength(textarea.value);
      counter.textContent = String(remaining);
      // OpenPNE 3 counter.js: red past the limit, orange for the last 25, black otherwise.
      counter.style.color = remaining < 0 ? '#FF0000' : (remaining <= 25 ? '#FFA500' : '#000000');
      submit.disabled = textarea.value === '' || remaining < 0;
    }

    function expand() {
      postform.style.paddingBottom = '30px';
      textarea.rows = 3;
      area.style.display = 'inline';
    }

    textarea.addEventListener('focus', expand);
    textarea.addEventListener('input', sync);
    if (photoButton) {
      photoButton.addEventListener('click', function () {
        fileInput.click();
      });
    }
    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (fileName) {
        fileName.textContent = file ? file.name : '';
      }
      if (photoRemove) {
        photoRemove.style.display = file ? 'inline' : 'none';
      }
    });
    if (photoRemove) {
      photoRemove.addEventListener('click', function () {
        fileInput.value = '';
        if (fileName) {
          fileName.textContent = '';
        }
        photoRemove.style.display = 'none';
      });
    }

    sync();
    // A failed POST reloads with the draft in place: keep the box open on what the member wrote.
    if (textarea.value !== '') {
      expand();
    }
    form.hidden = false;
    return true;
  }

  var forms = document.querySelectorAll('[data-timeline-compose]');
  var allReady = forms.length > 0;
  var i;
  for (i = 0; i < forms.length; i += 1) {
    if (!setUp(forms[i])) {
      allReady = false;
    }
  }
  // The links retire only once every box on the page took over; a box that failed to wire keeps
  // its no-JS path alive.
  if (allReady) {
    var fallbacks = document.querySelectorAll('[data-timeline-compose-fallback]');
    for (i = 0; i < fallbacks.length; i += 1) {
      fallbacks[i].hidden = true;
    }
  }
})();
