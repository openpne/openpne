@php
    $generated = app(\App\Files\GeneratedIcon::class);
    $wanted = \Illuminate\Support\Facades\Auth::guard('admin')->check()
        && app(\App\Files\SiteIcon::class)->state() !== \App\Files\SiteIconState::Uploaded
        && ! $generated->hasRaster();
@endphp
@if ($wanted)
{{-- The administrator's browser draws the generated icon's raster forms with its own fonts and
     sends them back (docs/internals/site-icon.md, "The generated icon"). --}}
<div
    hidden
    data-site-icon-raster
    data-version="{{ $generated->version() }}"
    data-url="{{ route('admin.site_icon.raster') }}"
    data-token="{{ csrf_token() }}"
    data-sizes="{{ implode(',', \App\Files\GeneratedIconRaster::SIZES) }}"
    data-svg-tab="{{ $generated->svg() }}"
    data-svg-tile="{{ $generated->svg(rounded: false) }}"
></div>
<script>
    (function () {
        var el = document.querySelector('[data-site-icon-raster]');
        if (!el || !window.sessionStorage || !window.FormData || !HTMLCanvasElement.prototype.toBlob) return;
        var key = 'op-site-icon-raster:' + el.dataset.version;
        try { if (sessionStorage.getItem(key)) return; } catch (e) { return; }

        function load(svg) {
            return new Promise(function (resolve, reject) {
                var url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
                var img = new Image();
                img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
                img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('svg')); };
                img.src = url;
            });
        }

        function draw(img, size) {
            return new Promise(function (resolve, reject) {
                var canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                canvas.getContext('2d').drawImage(img, 0, 0, size, size);
                canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('png')); }, 'image/png');
            });
        }

        Promise.all([load(el.dataset.svgTab), load(el.dataset.svgTile)]).then(function (images) {
            var sizes = el.dataset.sizes.split(',').map(Number);
            return Promise.all(sizes.map(function (size) {
                return draw(size === 32 ? images[0] : images[1], size);
            })).then(function (blobs) {
                var form = new FormData();
                form.append('version', el.dataset.version);
                sizes.forEach(function (size, i) { form.append('icon[' + size + ']', blobs[i], size + '.png'); });
                return fetch(el.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': el.dataset.token, 'Accept': 'application/json' },
                    body: form,
                    credentials: 'same-origin',
                });
            });
        }).then(function (response) {
            // 409 is a draw for a name or color since changed: the next page load draws the new one.
            if (response.status === 204 || response.status === 409) sessionStorage.setItem(key, '1');
        }).catch(function (error) {
            if (window.console && console.debug) console.debug('site icon raster', error);
        });
    })();
</script>
@endif
