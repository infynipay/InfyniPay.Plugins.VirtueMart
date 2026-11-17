/**
 * Infynipay Admin Setup Button & Card Interactions
 * Handles: dark-mode logo switching, responsive grid sync, SVG MIME fix, connected-only visibility
 */

document.addEventListener('DOMContentLoaded', function () {

    // Inputs for connected-only visibility (Joomla naming convention)
    var licenseInput = document.querySelector('input[name="jform[params][license_id]"]');

    // Dark-mode detection
    var isDarkTheme = function () {
        var root = document.documentElement;
        if (root.getAttribute('data-bs-theme') === 'dark'
            || document.body.classList.contains('joomla-color-scheme-dark')) {
            return true;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    };

    // Provider image theme switch
    var initProviderImages = function () {
        var useDark = isDarkTheme();

        document.querySelectorAll('.ph-provider-art').forEach(function (image) {
            var lightSrc = image.dataset.lightSrc || '';
            var darkSrc  = image.dataset.darkSrc  || '';
            var preferredSrc = useDark ? (darkSrc  || lightSrc) : (lightSrc || darkSrc);
            var fallbackSrc  = useDark ? (lightSrc || '')       : (darkSrc  || '');

            if (preferredSrc) {
                image.style.display = '';
                var item = image.closest('.ph-provider-item');
                if (item) { item.style.display = ''; }

                image.dataset.fallbackSrc = fallbackSrc;

                if (image.getAttribute('src') !== preferredSrc) {
                    image.setAttribute('src', preferredSrc);
                }
            }
        });
    };

    // Responsive grid column sync (override Bootstrap conflicts)
    var syncProviderGrid = function () {
        document.querySelectorAll('.ph-providers-grid').forEach(function (grid) {
            var width = grid.clientWidth;
            var columns = 5;

            if (width <= 420)      { columns = 2; }
            else if (width <= 560) { columns = 3; }
            else if (width <= 660) { columns = 4; }

            grid.style.display = 'grid';
            grid.style.gridTemplateColumns = 'repeat(' + columns + ', minmax(0, 1fr))';
            grid.style.gridAutoRows = 'minmax(48px, auto)';
            grid.style.gap = '0.5rem';

            grid.querySelectorAll('.ph-provider-item').forEach(function (item) {
                item.style.width   = '100%';
                item.style.maxWidth = 'none';
                item.style.minWidth = '0';
                item.style.flex    = 'none';
            });
        });
    };

    // SVG MIME type fix (Azure Blob Storage serves .svg with application/octet-stream)
    var svgBlobCache = {};

    var fixSvgImages = function () {
        if (!window.fetch || !window.Blob || !window.URL || !URL.createObjectURL) { return; }

        document.querySelectorAll('.ph-provider-art').forEach(function (img) {
            var src = img.getAttribute('src') || '';

            if (!src || src.slice(0, 5) === 'blob:' || src.slice(0, 5) === 'data:') { return; }

            var base = src.split('?')[0].toLowerCase();
            if (base.slice(-4) !== '.svg') { return; }

            if (svgBlobCache[src]) {
                img.src = svgBlobCache[src];
                return;
            }

            fetch(src, { mode: 'cors', credentials: 'omit' })
                .then(function (r) {
                    var ct = (r.headers.get('content-type') || '').toLowerCase();
                    if (ct.indexOf('svg') !== -1) { return null; }
                    return r.text();
                })
                .then(function (text) {
                    if (!text) { return; }
                    var blob = new Blob([text], { type: 'image/svg+xml' });
                    var url  = URL.createObjectURL(blob);
                    svgBlobCache[src] = url;
                    if (img.getAttribute('src') === src) {
                        img.removeAttribute('onerror');
                        img.src = url;
                    }
                })
                .catch(function () {});
        });
    };

    // Control-group class assignment (Joomla layout helper).
    // NOTE: we deliberately do NOT push the label-less setup cards with a padding-left.
    // Joomla keeps the (empty) label column for the Setup field, so its card already
    // sits in the controls column — naturally aligned with .ph-providers-card in every
    // viewport. Adding any offset only DOUBLE-shifts it and breaks that alignment.
    var initCardRows = function () {
        document.querySelectorAll(
            '.ph-card-connected, .ph-card-disconnected, .ph-providers-card'
        ).forEach(function (card) {
            var row = card.closest('.control-group');
            if (row) { row.classList.add('ph-infynipay-control-group'); }
        });

        [
            'sandbox', 'setup_button', 'providers_overview',
            'payment_status_success', 'payment_status_pending',
            'payment_status_failed', 'payment_status_cancelled'
        ].forEach(function (fieldName) {
            var field = document.querySelector('[name="jform[params][' + fieldName + ']"]');
            var row = field ? field.closest('.control-group') : null;
            if (row) { row.classList.add('ph-infynipay-control-group'); }
        });
    };

    // Connected-only visibility sync
    var connectedRows = [];

    document.querySelectorAll('.ph-connected-only').forEach(function (element) {
        var row = element.closest('.control-group, .control-wrapper, .mb-3, .field-spacer, .form-group')
                || element.closest('fieldset, .controls, .control-field');
        if (row) { connectedRows.push(row); }
    });

    var syncVisibility = function () {
        var isConnected = licenseInput ? licenseInput.value.trim() !== '' : false;
        connectedRows.forEach(function (row) {
            row.style.display = isConnected ? '' : 'none';
        });
    };

    // Skeleton release helpers
    var whenImageSettles = function (img, cb) {
        if (!img || (img.complete && img.currentSrc)) { cb(); return; }
        img.addEventListener('load',  cb, { once: true });
        img.addEventListener('error', cb, { once: true });
    };

    // Release a skeleton element only after BOTH its content has settled AND a
    // minimum visible window has elapsed. This guarantees the shimmer is actually
    // perceptible even when the image is served instantly from cache (localhost),
    // while still waiting on the real load/error signal for slow networks.
    // A hard safety timeout removes the skeleton no matter what.
    var releaseWhenSettled = function (el, img, minVisibleMs, safetyMs) {
        var settled    = false;
        var minElapsed = false;
        var done       = false;

        var finish = function () {
            if (done) { return; }
            done = true;
            el.classList.remove('ph-is-loading');
        };
        var tryFinish = function () {
            if (settled && minElapsed) { finish(); }
        };

        whenImageSettles(img, function () { settled = true; tryFinish(); });
        window.setTimeout(function () { minElapsed = true; tryFinish(); }, minVisibleMs);
        window.setTimeout(finish, safetyMs); // hard safety net — never get stuck
    };

    var releaseSkeleton = function () {
        // Connected-state: release ALL gateway cards together when the header logo
        // settles (the cards contain the action button — keep them in sync).
        document.querySelectorAll('.ph-card-connected').forEach(function (card) {
            var logo  = card.querySelector('.ph-logo--on-light, .ph-logo--on-dark');
            var cards = card.querySelectorAll('.ph-gateway-card.ph-is-loading');

            var settled = false, minElapsed = false, done = false;
            var finish = function () {
                if (done) { return; }
                done = true;
                cards.forEach(function (gc) { gc.classList.remove('ph-is-loading'); });
            };
            var tryFinish = function () { if (settled && minElapsed) { finish(); } };

            whenImageSettles(logo, function () { settled = true; tryFinish(); });
            window.setTimeout(function () { minElapsed = true; tryFinish(); }, 450);
            window.setTimeout(finish, 2500);
        });

        // Disconnected card: release when its header logo settles.
        document.querySelectorAll('.ph-card-disconnected.ph-is-loading').forEach(function (card) {
            var logo = card.querySelector('.ph-logo--on-light, .ph-logo--on-dark');
            releaseWhenSettled(card, logo, 450, 2500);
        });

        // Supported-gateways grid: every provider logo tile gets its own skeleton,
        // released when THAT tile's icon settles (min-visible window keeps it shown
        // even for instantly-cached icons).
        document.querySelectorAll('.ph-provider-item.ph-is-loading').forEach(function (item) {
            var img = item.querySelector('img');
            releaseWhenSettled(item, img, 550, 3000);
        });
    };

    // Boot
    initCardRows();
    initProviderImages();
    fixSvgImages();
    syncProviderGrid();
    releaseSkeleton();

    window.addEventListener('resize', syncProviderGrid);

    if (window.ResizeObserver) {
        document.querySelectorAll('.ph-providers-card').forEach(function (card) {
            new ResizeObserver(syncProviderGrid).observe(card);
        });
    }

    if (!licenseInput) { return; }

    syncVisibility();

    var observer = new MutationObserver(syncVisibility);
    observer.observe(licenseInput, { attributes: true, attributeFilter: ['value'] });
    licenseInput.addEventListener('change', syncVisibility);

});
