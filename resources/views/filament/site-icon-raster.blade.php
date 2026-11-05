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
    data-color="{{ $generated->color() }}"
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

        var color = el.dataset.color;
        var brand = [1, 3, 5].map(function (i) { return parseInt(color.substr(i, 2), 16); });

        // A browser that guards against canvas fingerprinting hands back noise instead of the
        // drawing: a pixel that must be the brand color is checked before anything is sent.
        function isBrand(ctx, x, y) {
            var p = ctx.getImageData(x, y, 1, 1).data;
            return p[3] === 255 && Math.abs(p[0] - brand[0]) <= 4 && Math.abs(p[1] - brand[1]) <= 4 && Math.abs(p[2] - brand[2]) <= 4;
        }

        function draw(img, size) {
            return new Promise(function (resolve, reject) {
                var canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, size, size);
                // The 32px icon is rounded: (3, 16) is inside its left edge; the square sizes check a corner.
                if (!(size === 32 ? isBrand(ctx, 3, 16) : isBrand(ctx, 2, 2))) return reject(new Error('canvas-noise'));
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
        }).catch(function (error) {
            // Only this is remembered: a browser that cannot draw it stays quiet for the session, while an
            // accepted draw takes this hook off the page and a stale one gets a new version on the next.
            if (error && error.message === 'canvas-noise') sessionStorage.setItem(key, '1');
            if (window.console && console.debug) console.debug('site icon raster', error);
        });
    })();
</script>
@endif
