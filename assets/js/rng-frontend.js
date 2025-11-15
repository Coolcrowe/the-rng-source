(function () {
    'use strict';

    /* ---------------------------------------------------------
       HELPER FUNCTIONS
    --------------------------------------------------------- */
    function $(selector) {
        return document.querySelector(selector);
    }

    function show(el) {
        if (el) el.classList.remove('bc-rng-hidden');
    }

    function hide(el) {
        if (el) el.classList.add('bc-rng-hidden');
    }

    function setText(el, text) {
        if (el) el.textContent = text;
    }

    /* ---------------------------------------------------------
       SERVER CLOCK (REAL TIME FROM BACKEND)
    --------------------------------------------------------- */
    function startServerClock() {
        var clockEl = document.getElementById('bc-rng-server-clock');
        if (!clockEl || !window.bc_rng_config) {
            console.log('[RNG] No server clock element or config found.');
            return;
        }

        function updateClock() {
            var formData = new FormData();
            formData.append('action', bc_rng_config.server_time_action);

            fetch(bc_rng_config.ajax_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success && data.data && data.data.server_time) {
                        clockEl.textContent = data.data.server_time;
                    }
                })
                .catch(function (err) {
                    console.error('[RNG] Server clock error:', err);
                });
        }

        updateClock();
        setInterval(updateClock, 1000);
    }

    /* ---------------------------------------------------------
       ANIMATION (NO LEADING ZEROS)
    --------------------------------------------------------- */
    function startAnimation(animationEl, min, max) {
        if (!animationEl) return null;

        animationEl.textContent = '';

        var start = Date.now();
        var duration = 2000;

        var timer = setInterval(function () {
            var now = Date.now();
            if (now - start >= duration) {
                clearInterval(timer);
                return;
            }

            var fake;
            if (Number.isFinite(min) && Number.isFinite(max) && min < max) {
                fake = Math.floor(Math.random() * (max - min + 1)) + min;
            } else {
                fake = Math.floor(Math.random() * 999999);
            }

            animationEl.textContent = String(fake);
        }, 60);

        return timer;
    }

    function stopAnimation(timer) {
        if (timer) clearInterval(timer);
    }

    /* ---------------------------------------------------------
       GENERATOR HANDLER
    --------------------------------------------------------- */
    function handleGenerator() {
        var form = $('#bc-rng-form');
        if (!form) {
            console.log('[RNG] Generator form not found.');
            return;
        }

        var animationEl = $('#bc-rng-animation');
        var statusEl = $('#bc-rng-status');
        var resultBlock = $('#bc-rng-result-block');
        var finalNumberEl = $('#bc-rng-final-number');
        var rangeEl = $('#bc-rng-range');
        var keyEl = $('#bc-rng-key');
        var seedHashEl = $('#bc-rng-seed-hash');
        var timestampEl = $('#bc-rng-timestamp');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();

            hide(resultBlock);
            setText(statusEl, '');

            var min = parseInt(form.querySelector('input[name="min"]').value, 10);
            var max = parseInt(form.querySelector('input[name="max"]').value, 10);
            var nonceInput = form.querySelector('input[name="bc_rng_nonce"]');

            if (!Number.isFinite(min) || !Number.isFinite(max)) {
                setText(statusEl, 'Please enter both minimum and maximum numbers.');
                statusEl.classList.add('bc-rng-status-error');
                return;
            }

            if (min >= max) {
                setText(statusEl, 'Minimum must be less than maximum.');
                statusEl.classList.add('bc-rng-status-error');
                return;
            }

            statusEl.classList.remove('bc-rng-status-error');
            setText(statusEl, 'Generating secure random number and saving result...');

            var timer = startAnimation(animationEl, min, max);

            var formData = new FormData();
            formData.append('action', bc_rng_config.generate_action);
            formData.append('min', String(min));
            formData.append('max', String(max));
            if (nonceInput) formData.append('bc_rng_nonce', nonceInput.value);

            fetch(bc_rng_config.ajax_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    stopAnimation(timer);
                    console.log('[RNG] Generator AJAX response:', data);

                    if (!data || !data.success) {
                        var msg = (data && data.data && data.data.message) || 'Something went wrong. Please try again.';
                        setText(statusEl, msg);
                        statusEl.classList.add('bc-rng-status-error');
                        return;
                    }

                    var payload = data.data;

                    animationEl.textContent = String(payload.result);

                    setText(finalNumberEl, payload.result);
                    setText(rangeEl, payload.min + ' to ' + payload.max);
                    setText(keyEl, payload.id);
                    setText(seedHashEl, payload.seed_hash);
                    setText(timestampEl, payload.timestamp);

                    show(resultBlock);
                    setText(statusEl, 'Draw complete. Result and verification key are ready.');
                })
                .catch(function (err) {
                    stopAnimation(timer);
                    console.error('[RNG] Generator AJAX error:', err);
                    setText(statusEl, 'Network error. Please try again.');
                    statusEl.classList.add('bc-rng-status-error');
                });
        });
    }

    /* ---------------------------------------------------------
       HASH UTILITIES
    --------------------------------------------------------- */
    function toHex(buffer) {
        var bytes = new Uint8Array(buffer);
        var hex = [];
        for (var i = 0; i < bytes.length; i++) {
            var h = bytes[i].toString(16);
            if (h.length === 1) h = '0' + h;
            hex.push(h);
        }
        return hex.join('');
    }

    function verifyHash(seed, expectedHash) {
        if (!window.crypto || !window.crypto.subtle) {
            return Promise.resolve(null);
        }

        var data = new TextEncoder().encode(seed);

        return window.crypto.subtle.digest('SHA-256', data).then(function (hashBuffer) {
            var hex = toHex(hashBuffer);
            return hex.toLowerCase() === expectedHash.toLowerCase();
        });
    }

    /* ---------------------------------------------------------
       VERIFY HANDLER
    --------------------------------------------------------- */
    function handleVerify() {
        var form = $('#bc-rng-verify-form');

        if (!form) {
            console.log('[RNG] Verify form not found.');
            return;
        }

        var statusEl = $('#bc-rng-verify-status');
        var blockEl = $('#bc-rng-verify-block');

        var finalNumberEl = $('#bc-rng-v-final-number');
        var rangeEl = $('#bc-rng-v-range');
        var seedEl = $('#bc-rng-v-seed');
        var seedHashEl = $('#bc-rng-v-seed-hash');
        var hashCheckEl = $('#bc-rng-v-hash-check');
        var timestampEl = $('#bc-rng-v-timestamp');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();

            hide(blockEl);
            setText(statusEl, '');
            setText(hashCheckEl, '');

            var key = form.querySelector('input[name="key"]').value.trim();

            if (!key) {
                setText(statusEl, 'Please enter a verification key.');
                statusEl.classList.add('bc-rng-status-error');
                return;
            }

            setText(statusEl, 'Looking up the stored draw...');
            statusEl.classList.remove('bc-rng-status-error');

            var formData = new FormData();
            formData.append('action', bc_rng_config.verify_action);
            formData.append('key', key);

            fetch(bc_rng_config.ajax_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.text(); })
                .then(function (text) {
                    console.log('[RNG] Verify raw response:', text);

                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        console.error('[RNG] Non-JSON verify response:', text);
                        setText(statusEl, 'Unexpected server response.');
                        statusEl.classList.add('bc-rng-status-error');
                        return;
                    }

                    if (!data.success) {
                        var msg = (data && data.data && data.data.message) || 'No result found for that key.';
                        setText(statusEl, msg);
                        statusEl.classList.add('bc-rng-status-error');
                        return;
                    }

                    var payload = data.data;

                    setText(finalNumberEl, payload.result);
                    setText(rangeEl, payload.min + ' to ' + payload.max);
                    setText(seedEl, payload.seed);
                    setText(seedHashEl, payload.seed_hash);
                    setText(timestampEl, payload.timestamp);

                    show(blockEl);
                    setText(statusEl, 'Stored draw found. Checking hash...');

                    verifyHash(payload.seed, payload.seed_hash).then(function (match) {
                        if (match === null) {
                            setText(hashCheckEl, 'Browser cannot compute SHA-256.');
                            return;
                        }

                        if (match) {
                            setText(hashCheckEl, 'Hash matches. Draw integrity verified.');
                        } else {
                            setText(hashCheckEl, 'Hash mismatch. Data may have been altered.');
                        }

                        setText(statusEl, 'Verification complete.');
                    });
                })
                .catch(function (err) {
                    console.error('[RNG] Verify AJAX error:', err);
                    setText(statusEl, 'Network error while verifying.');
                    statusEl.classList.add('bc-rng-status-error');
                });
        });
    }

    /* ---------------------------------------------------------
       INIT
    --------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        console.log('[RNG] Initialising...');
        handleGenerator();
        handleVerify();
        startServerClock();
    });

})();
