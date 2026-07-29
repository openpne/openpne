/*
 * The OpenPNE 3 inline compose behaviour for the Classic timeline (_timelineAll's script,
 * server-rendered form): reveal the form, retire its no-JS fallback link, expand on focus,
 * count down from 140, gate the submit, and proxy the offscreen file input through the camera
 * button. Every lookup is scoped to one form — the OpenPNE 3 ids repeat when two home gadgets
 * both render a compose box — and the reveal comes last, so a failure anywhere leaves the
 * hidden form and the working link.
 */
(function () {
  'use strict';

  var MAXLENGTH = 140;

  // OpenPNE 3's counter (counter.js) counts a newline as two characters, matching the CRLF the
  // browser submits and the server's max:140 over it.
  function submittedLength(value) {
    return value.replace(/\r\n|\r|\n/g, '\r\n').length;
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
      var remaining = MAXLENGTH - submittedLength(textarea.value);
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
