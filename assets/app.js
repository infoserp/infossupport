// Small progressive enhancements. Every rule enforced here is also enforced on the server.
(function () {
    
    var narrow = window.matchMedia('(max-width: 900px)');
    // Phones: filter panels start folded behind their "Filters" button unless a filter is set.
    var folds = document.querySelectorAll('details.filter-fold');
    var fold = function () { folds.forEach(function (d) { d.open = !narrow.matches || d.getAttribute('data-active') !== '0'; }); };
    fold();
    narrow.addEventListener('change', fold);
    // Phones: the menu button in the top bar shows or hides the navigation.
    var navToggle = document.querySelector('.nav-toggle');
    if (navToggle) navToggle.addEventListener('click', function () {
        var open = document.getElementById('sidebar').classList.toggle('open');
        navToggle.setAttribute('aria-expanded', String(open));
    });
    document.querySelectorAll('.sidebar .nav-group').forEach(function (group) {
        var head = group.querySelector('.nav-link:not(.nav-sub)');
        var subs = group.querySelectorAll('.nav-sub');
        if (!head || !subs.length || (subs.length === 1 && subs[0].href === head.href)) return;
        group.classList.add('has-menu');
        head.setAttribute('aria-expanded', 'false');
        head.addEventListener('click', function (e) {
            if (!narrow.matches) return;
            e.preventDefault();
            var open = !group.classList.contains('open');
            document.querySelectorAll('.sidebar .nav-group.open').forEach(function (g) {
                g.classList.remove('open');
                g.querySelector('.nav-link:not(.nav-sub)').setAttribute('aria-expanded', 'false');
            });
            group.classList.toggle('open', open);
            head.setAttribute('aria-expanded', String(open));
        });
    });

   
    var modal = null;
    var pending = null;
    var buildModal = function () {
        modal = document.createElement('dialog');
        modal.className = 'modal';
        modal.setAttribute('aria-labelledby', 'modal-title');
        modal.innerHTML =
            '<div class="modal-body">' +
                '<span class="mi modal-icon" aria-hidden="true">help</span>' +
                '<div><h2 id="modal-title" class="modal-title">Please confirm</h2><p class="modal-text"></p></div>' +
            '</div>' +
            '<div class="modal-actions">' +
                '<button type="button" class="btn" data-modal-cancel>Cancel</button>' +
                '<button type="button" class="btn btn-primary" data-modal-ok>Confirm</button>' +
            '</div>';
        document.body.appendChild(modal);
        modal.querySelector('[data-modal-cancel]').addEventListener('click', function () { modal.close(); });
        modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });   // click on backdrop
        modal.addEventListener('close', function () { pending = null; });
        modal.querySelector('[data-modal-ok]').addEventListener('click', function () {
            var el = pending;
            modal.close();
            if (!el) return;
            el.dataset.confirmed = '1';
            if (el.form) { el.form.requestSubmit(el.type === 'submit' ? el : undefined); }
            else if (el.href) { window.location.href = el.href; }
            else { el.click(); }
        });
    };
    // Call time: shows the duration as you pick, flags To <= From at once, and offers Clear only when a time is set.
    document.querySelectorAll('[data-time-range]').forEach(function (fs) {
        var from = fs.querySelector('[name=time_from]'), to = fs.querySelector('[name=time_to]');
        var note = fs.querySelector('[data-time-note]'), clear = fs.querySelector('[data-clear-fields]');
        var mins = function (t) { return +t.slice(0, 2) * 60 + +t.slice(3, 5); };
        var update = function () {
            var msg = '', bad = false;
            if (from.value && to.value) {
                var m = mins(to.value) - mins(from.value);
                bad = m <= 0;
                msg = bad ? 'To must be later than From' : (m >= 60 ? Math.floor(m / 60) + ' h ' : '') + (m % 60 ? m % 60 + ' m' : '');
            }
            note.textContent = msg.trim();
            note.classList.toggle('text-red', bad);
            to.setCustomValidity(bad ? 'The To time must be later than the From time.' : '');
            clear.classList.toggle('hidden', !from.value && !to.value);
        };
        [from, to].forEach(function (i) { i.addEventListener('input', update); i.addEventListener('change', update); });
        update();
    });
    // "Clear" buttons empty the inputs of their fieldset (e.g. the optional call time).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-clear-fields]');
        if (!btn) return;
        btn.closest('fieldset').querySelectorAll('input').forEach(function (i) {
            i.value = '';
            i.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el) return;
        if (el.dataset.confirmed) { delete el.dataset.confirmed; return; }   // second pass: let it through
        // Let in-page validation run first, so the dialog never confirms an incomplete form.
        if (el.form && !validateForm(el.form)) { e.preventDefault(); return; }
        e.preventDefault();
        if (!modal) buildModal();
        pending = el;
        // data-confirm-title / data-confirm-ok name the action; data-confirm-danger marks it destructive.
        var danger = el.hasAttribute('data-confirm-danger') || el.classList.contains('btn-danger');
        var ok = modal.querySelector('[data-modal-ok]');
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        // Otherwise reuse the clicked button's label, minus its Material icon ligature text (e.g. "delete").
        var icon = el.querySelector('.mi');
        var label = el.textContent.replace(icon ? icon.textContent : '', '').trim();
        ok.textContent = el.getAttribute('data-confirm-ok') || label || 'Confirm';
        modal.querySelector('.modal-icon').textContent = danger ? 'warning' : 'help';
        modal.querySelector('.modal-icon').classList.toggle('text-red', danger);
        modal.querySelector('.modal-title').textContent = el.getAttribute('data-confirm-title') || (danger ? 'Are you sure?' : 'Please confirm');
        modal.querySelector('.modal-text').textContent = el.getAttribute('data-confirm');
        modal.showModal();
        modal.querySelector('[data-modal-cancel]').focus();
    });

    // In-page form validation: replaces the browser's "Please fill out this field" bubbles
    // with messages under each field. The server validates everything again regardless.
    var messageFor = function (f) {
        var v = f.validity;
        if (v.valueMissing) return f.type === 'radio' ? 'Please choose an option.' : (f.tagName === 'SELECT' ? 'Please select an option.' : 'This field is required.');
        if (v.tooShort) return 'Must be at least ' + f.minLength + ' characters.';
        if (v.tooLong) return 'Must be ' + f.maxLength + ' characters or fewer.';
        if (v.patternMismatch) return f.getAttribute('data-pattern-msg') || 'Use letters, numbers, dot, dash or underscore only.';
        if (v.stepMismatch && f.type === 'time') return 'Use 5-minute steps, e.g. 09:45 or 09:50.';
        if (v.rangeOverflow) return f.type === 'date' ? 'Date cannot be in the future.' : 'Value is too high.';
        if (v.typeMismatch || v.badInput) return 'Please enter a valid value.';
        return f.validationMessage;
    };
    var anchorFor = function (f) {
        // Radios: put the message after the whole group; otherwise after the input/combobox.
        if (f.type === 'radio') return f.closest('fieldset') || f.parentNode;
        return f.closest('.combo') || f.closest('.input-prefix') || f;
    };
    var clearError = function (f) {
        var a = anchorFor(f);
        var next = a.nextElementSibling;
        if (next && next.classList.contains('field-error')) next.remove();
        if (f.type === 'radio') {
            (f.form ? f.form.querySelectorAll('input[type=radio][name="' + f.name + '"]') : [f]).forEach(function (r) { r.removeAttribute('aria-invalid'); });
        } else {
            f.removeAttribute('aria-invalid');
        }
    };
    var showError = function (f, msg) {
        clearError(f);
        var p = document.createElement('p');
        p.className = 'field-error';
        p.setAttribute('role', 'alert');
        p.innerHTML = '<span class="mi" aria-hidden="true">error</span>';
        p.appendChild(document.createTextNode(msg));
        var a = anchorFor(f);
        a.parentNode.insertBefore(p, a.nextSibling);
        if (f.type === 'radio' && f.form) {
            f.form.querySelectorAll('input[type=radio][name="' + f.name + '"]').forEach(function (r) { r.setAttribute('aria-invalid', 'true'); });
        } else {
            f.setAttribute('aria-invalid', 'true');
        }
    };
    var validateForm = function (form) {
        var first = null;
        var seenRadio = {};
        Array.prototype.forEach.call(form.elements, function (f) {
            if (!f.willValidate || f.type === 'hidden') return;
            if (f.type === 'radio') {
                if (seenRadio[f.name]) return;
                seenRadio[f.name] = true;
            }
            if (f.checkValidity()) { clearError(f); return; }
            showError(f, messageFor(f));
            if (!first) first = f;
        });
        if (first) {
            var d = first.closest('details');
            if (d) d.open = true;
            first.focus();
            return false;
        }
        return true;
    };
    document.querySelectorAll('form').forEach(function (form) {
        form.noValidate = true;   // turn off the browser's bubbles; we show our own
        form.addEventListener('submit', function (e) {
            if (e.submitter && e.submitter.formNoValidate) return;   // e.g. "New customer": leaves the form unfinished on purpose
            if (!validateForm(form)) { e.preventDefault(); e.stopImmediatePropagation(); }
        });
        var fix = function (e) {
            var f = e.target;
            if (f.willValidate && f.getAttribute('aria-invalid') === 'true' && f.checkValidity()) clearError(f);
        };
        form.addEventListener('input', fix);
        form.addEventListener('change', fix);
    });

    // Add / Edit Support Call: warn immediately when a No Support / Inactive customer is selected.
    var select = document.getElementById('customer-select');
    var banner = document.getElementById('stop-banner');
    if (select && banner) {
        var text = document.getElementById('stop-text');
        var saves = document.querySelectorAll('#call-form [data-save]');
        var update = function () {
            var opt = select.options[select.selectedIndex];
            var status = opt ? opt.getAttribute('data-status') : null;
            // When editing, the call's original customer stays allowed even if it is now restricted.
            var original = opt && opt.getAttribute('data-original') === '1';
            var blocked = !original && (status === 'NoSupport' || status === 'Inactive');
            if (blocked) {
                text.textContent = select.getAttribute(status === 'NoSupport' ? 'data-msg-nosupport' : 'data-msg-inactive');
            }
            banner.classList.toggle('hidden', !blocked);
            select.setAttribute('aria-invalid', blocked ? 'true' : 'false');
            saves.forEach(function (b) { b.disabled = blocked; });
            var category = document.getElementById('customer-category');   // read-only, from the ERP
            if (category) {
                category.textContent = opt ? (opt.getAttribute('data-category') || '') : '';
                document.getElementById('customer-category-note').classList.toggle('hidden', category.textContent === '');
            }
        };
        select.addEventListener('change', update);
        update();
    }

    // Searchable select: <select data-searchable> becomes a type-to-filter combobox.
    // The original select stays in the form (hidden) and receives the chosen value + a change event.
    // With data-source="url" the options come from the server as you type (url?q= returns
    // [{v: value, t: label, s: status}]), for lists too long to put in the page.
    document.querySelectorAll('select[data-searchable]').forEach(function (sel, n) {
        var allLabel = sel.options[0] && sel.options[0].value === '' ? sel.options[0].text : '';
        var wrap = document.createElement('div');
        wrap.className = 'combo';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'combo-input';
        input.autocomplete = 'off';
        input.placeholder = allLabel || 'Search…';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', 'combo-list-' + n);
        var list = document.createElement('ul');
        list.className = 'combo-list hidden';
        list.id = 'combo-list-' + n;
        list.setAttribute('role', 'listbox');
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        wrap.appendChild(input);
        wrap.appendChild(list);
        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'combo-clear';
        clear.title = 'Clear selection';
        clear.setAttribute('aria-label', 'Clear selection');
        clear.innerHTML = '<span class="mi" aria-hidden="true">close</span>';
        wrap.appendChild(clear);
        sel.classList.add('hidden');
        sel.tabIndex = -1;

        var active = -1;
        var src = sel.getAttribute('data-source');
        var hits = {};          // values returned by the latest server search
        var pending = false;
        var timer = null;
        var asked = null;
        var fetchOptions = function (q) {
            pending = true;
            clearTimeout(timer);
            timer = setTimeout(function () {
                asked = q;
                fetch(src + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (rows) {
                        if (asked !== q) return;   // a newer search has started
                        // Keep the current and original choice so the form value survives a new search.
                        Array.prototype.slice.call(sel.options).forEach(function (o) {
                            if (o.value !== '' && !o.selected && o.getAttribute('data-original') !== '1') o.remove();
                        });
                        hits = {};
                        rows.forEach(function (r) {
                            hits[r.v] = true;
                            if (sel.querySelector('option[value="' + CSS.escape(r.v) + '"]')) return;
                            var o = new Option(r.t, r.v);
                            o.setAttribute('data-status', r.s);
                            o.setAttribute('data-category', r.c || '');
                            sel.add(o);
                        });
                        pending = false;
                        if (!list.classList.contains('hidden')) render(q);
                    })
                    .catch(function () { pending = false; });
            }, 200);
        };
        var labelOf = function () {
            var o = sel.options[sel.selectedIndex];
            return o && o.value !== '' ? o.text.trim() : '';
        };
        var visible = function () { return list.querySelectorAll('.combo-opt'); };
        var setActive = function (i) {
            var items = visible();
            if (!items.length) { active = -1; return; }
            active = (i + items.length) % items.length;
            items.forEach(function (li, k) { li.classList.toggle('active', k === active); });
            items[active].scrollIntoView({ block: 'nearest' });
        };
        var render = function (q) {
            q = (q || '').trim().toLowerCase();
            list.innerHTML = '';
            var shown = 0;
            Array.prototype.forEach.call(sel.options, function (o) {
                var label = o.text.trim();
                if (src) {
                    // Server-fed: show what the last search returned; the blank prompt is not a choice.
                    if (o.value === '' || !hits[o.value]) return;
                } else if (q && o.value !== '' && label.toLowerCase().indexOf(q) === -1) return;
                var li = document.createElement('li');
                li.className = 'combo-opt' + (o.value === '' ? ' combo-all' : '') + (o.selected ? ' selected' : '');
                li.setAttribute('role', 'option');
                li.dataset.value = o.value;
                li.textContent = label;
                list.appendChild(li);
                if (o.value !== '') shown++;
            });
            if (!shown) {
                var none = document.createElement('li');
                none.className = 'combo-none';
                none.textContent = src && pending ? 'Searching…' : 'No matches';
                list.appendChild(none);
            }
            setActive(q && !src && list.children.length > 1 ? 1 : 0);
        };
        var open = function () {
            var q = input.value === labelOf() ? '' : input.value;
            if (src) fetchOptions(q);
            render(q);
            list.classList.remove('hidden');
            input.setAttribute('aria-expanded', 'true');
        };
        var close = function () {
            list.classList.add('hidden');
            input.setAttribute('aria-expanded', 'false');
            input.value = labelOf();
            wrap.classList.toggle('has-value', sel.value !== '');
        };
        var choose = function (value) {
            var changed = sel.value !== value;
            sel.value = value;
            close();
            if (changed) sel.dispatchEvent(new Event('change', { bubbles: true }));
        };

        close();
        sel.addEventListener('change', close);   // also when the value is changed from outside (e.g. the task list)
        clear.addEventListener('click', function () { choose(''); input.focus(); });
        input.addEventListener('focus', function () { input.select(); open(); });
        input.addEventListener('input', function () { open(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (list.classList.contains('hidden')) open(); else setActive(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var item = visible()[active];
                if (item && !list.classList.contains('hidden')) choose(item.dataset.value);
            }
            else if (e.key === 'Escape') { close(); input.blur(); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
        list.addEventListener('mousedown', function (e) {
            var li = e.target.closest('.combo-opt');
            if (li) { e.preventDefault(); choose(li.dataset.value); }
        });
    });

    // Live filter form: <form data-live="targetId"> re-fetches the page and swaps the results
    // whenever a filter changes, so there is no Apply button to click.
    document.querySelectorAll('form[data-live]').forEach(function (form) {
        var target = document.getElementById(form.getAttribute('data-live'));
        if (!target) return;
        var status = form.querySelector('.live-status');
        var ctrl = null;
        var timer = null;

        var last = null;
        var run = function () {
            var params = new URLSearchParams();
            new FormData(form).forEach(function (v, k) { if (v !== '') params.append(k, v); });
            var url = location.pathname + (params.toString() ? '?' + params.toString() : '');
            if (url === last) return;   // e.g. leaving a search box fires change after the typing already ran
            last = url;
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            if (status) status.textContent = 'Updating…';
            target.classList.add('is-loading');
            fetch(url, { signal: ctrl.signal, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById(target.id);
                    if (!fresh) { location.href = url; return; }   // e.g. session expired → follow normally
                    target.innerHTML = fresh.innerHTML;
                    ['export-pdf', 'export-xlsx'].forEach(function (id) {
                        var a = document.getElementById(id), b = doc.getElementById(id);
                        if (a && b) a.setAttribute('href', b.getAttribute('href'));
                    });
                    history.replaceState(null, '', url);
                    if (status) status.textContent = '';
                })
                .catch(function (err) { last = null; if (err.name !== 'AbortError' && status) status.textContent = 'Could not update results.'; })
                .then(function () { target.classList.remove('is-loading'); });
        };
        var later = function () { clearTimeout(timer); timer = setTimeout(run, 300); };

        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('combo-input')) return;   // the hidden select fires its own change
            later();
        });
        form.addEventListener('input', function (e) {
            if (e.target.type === 'date' || e.target.type === 'search') later();
        });
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
    });
})();

// Light / dark mode toggle (the initial theme is applied by an inline script in <head>).
(function () {
    var root = document.documentElement;
    var current = function () {
        var t = root.getAttribute('data-theme');
        if (t) return t;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = current() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
        });
    });
})();
// Time wheel: <input type="time" data-wheel> gets a clock button that opens an iOS-style wheel
// (12-hour with AM/PM, 5-minute steps). It closes only with Done, Cancel or Esc; typing in the box still works.
(function () {
    var inputs = document.querySelectorAll('input[type=time][data-wheel]');
    if (!inputs.length) return;
    var H = 36, ANGLE = 20, R = Math.round(H / 2 / Math.tan(ANGLE * Math.PI / 360));   // row height, degrees per row, wheel radius
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var range = function (n, step) { var a = []; for (var i = 0; i < n; i++) a.push(pad(i * step)); return a; };
    var dlg, title, cols, target, opener;

    var column = function (label, values) {
        var col = document.createElement('div');
        col.className = 'tw-col';
        var wheel = document.createElement('div');
        wheel.className = 'tw-wheel';
        wheel.setAttribute('aria-hidden', 'true');
        var list = document.createElement('ul');
        list.className = 'tw-list';
        list.tabIndex = 0;
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', label);
        var c = { list: list, index: 0, target: 0, moving: false };
        var faces = [];
        values.forEach(function (v, i) {
            var face = document.createElement('div');
            face.textContent = v;
            face.style.transform = 'rotateX(' + (-i * ANGLE) + 'deg) translateZ(' + R + 'px)';
            wheel.appendChild(face);
            faces.push(face);
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.textContent = v;
            li.addEventListener('click', function () { c.go(i); });
            list.appendChild(li);
        });
        col.appendChild(wheel);
        col.appendChild(list);
        var clamp = function (i) { return Math.max(0, Math.min(values.length - 1, i)); };
        // The wheel turns with the list's scroll position: one row of scroll = one row of rotation.
        var frame = 0, idle;
        var paint = function () {
            frame = 0;
            var pos = list.scrollTop / H;
            wheel.style.transform = 'translateZ(' + (-R) + 'px) rotateX(' + (pos * ANGLE) + 'deg)';
            // Only rows on the front half of the wheel show: 24 hours go more than once round it,
            // so a row a full turn away would otherwise print on top of the visible one.
            faces.forEach(function (f, j) { f.style.visibility = Math.abs(j - pos) * ANGLE < 90 ? '' : 'hidden'; });
            var i = clamp(Math.round(pos));
            if (i !== c.index || !list.querySelector('[aria-selected=true]')) {
                Array.prototype.forEach.call(list.children, function (li, j) { li.setAttribute('aria-selected', String(j === i)); });
                c.index = i;
            }
        };
        list.addEventListener('scroll', function () {
            if (!frame) frame = requestAnimationFrame(paint);
            clearTimeout(idle);
            idle = setTimeout(function () { c.moving = false; c.target = c.index; }, 150);
        });
        c.go = function (i, instant) {
            c.target = clamp(i);
            c.moving = !instant;
            list.scrollTo({ top: c.target * H, behavior: instant || reduce.matches ? 'auto' : 'smooth' });
            if (instant) paint();
        };
        c.picked = function () { return c.moving ? c.target : c.index; };
        // Mouse wheel / trackpad: one row per notch, not the browser's three.
        var acc = 0;
        list.addEventListener('wheel', function (e) {
            e.preventDefault();
            acc += e.deltaY;
            if (Math.abs(acc) < 40) return;
            c.go(c.target + (acc > 0 ? 1 : -1));
            acc = 0;
        }, { passive: false });
        list.addEventListener('keydown', function (e) {
            var step = { ArrowDown: 1, ArrowUp: -1, PageDown: 4, PageUp: -4 }[e.key];
            if (step) c.go(c.target + step);
            else if (e.key === 'Home') c.go(0);
            else if (e.key === 'End') c.go(values.length - 1);
            else if (e.key === 'Enter') done();
            else return;
            e.preventDefault();
        });
        return { el: col, c: c };
    };

    var done = function () {
        // 12-hour wheel -> the box's 24-hour value: 12 AM is 00, 12 PM is 12.
        target.value = pad((cols[0].picked() + 1) % 12 + 12 * cols[2].picked()) + ':' + pad(cols[1].picked() * 5);
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
        dlg.close();
    };

    var build = function () {
        dlg = document.createElement('dialog');
        dlg.className = 'tw';
        dlg.tabIndex = -1;
        dlg.setAttribute('aria-labelledby', 'tw-title');
        dlg.innerHTML = '<div class="tw-head" id="tw-title"></div><div class="tw-body"></div>'
            + '<div class="tw-foot"><button type="button" class="btn" data-tw-cancel>Cancel</button>'
            + '<button type="button" class="btn btn-primary" data-tw-done>Done</button></div>';
        title = dlg.querySelector('.tw-head');
        var h = column('Hours', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12']), m = column('Minutes', range(12, 5)), ap = column('AM or PM', ['AM', 'PM']);
        ap.el.classList.add('tw-ampm');
        var sep = document.createElement('span');
        sep.className = 'tw-sep';
        sep.textContent = ':';
        dlg.querySelector('.tw-body').append(h.el, sep, m.el, ap.el);
        cols = [h.c, m.c, ap.c];
        dlg.querySelector('[data-tw-cancel]').addEventListener('click', function () { dlg.close(); });
        dlg.querySelector('[data-tw-done]').addEventListener('click', done);
        dlg.addEventListener('close', function () { if (opener) opener.focus(); });
        document.body.appendChild(dlg);
    };

    var open = function (input, btn, fromKeyboard) {
        if (!dlg) build();
        target = input;
        opener = btn;
        var v = input.value;
        if (!v) {
            // An empty To starts 30 minutes after From; otherwise at the current time.
            var first = input.closest('[data-time-range]') && input.closest('[data-time-range]').querySelector('input[type=time]');
            var base = first && first !== input && first.value ? first.value : null;
            var d = new Date();
            var mins = base ? +base.slice(0, 2) * 60 + +base.slice(3, 5) + 30 : d.getHours() * 60 + d.getMinutes();
            v = pad(Math.floor(mins / 60) % 24) + ':' + pad(mins % 60);
        }
        var hh = +v.slice(0, 2), mm = Math.round(+v.slice(3, 5) / 5);
        if (mm === 12) { mm = 0; hh = (hh + 1) % 24; }
        title.textContent = btn.getAttribute('aria-label').replace('Choose ', '');
        // Desktop: open next to the box; phones: a sheet from the bottom (CSS).
        dlg.style.top = dlg.style.left = '';
        dlg.showModal();
        if (!window.matchMedia('(max-width: 900px)').matches) {
            var r = input.closest('.input-prefix').getBoundingClientRect(), dh = dlg.offsetHeight, dw = dlg.offsetWidth;
            var top = r.bottom + 6 + dh > innerHeight ? Math.max(8, r.top - 6 - dh) : r.bottom + 6;
            dlg.style.top = top + 'px';
            dlg.style.left = Math.max(8, Math.min(r.left, innerWidth - dw - 8)) + 'px';
        }
        cols[0].go((hh % 12 || 12) - 1, true);
        cols[1].go(mm, true);
        cols[2].go(hh < 12 ? 0 : 1, true);
        // Keyboard users land on the hours; a mouse or finger opening shows no focus ring.
        if (fromKeyboard) cols[0].list.focus(); else dlg.focus();
    };

    inputs.forEach(function (input) {
        var label = input.closest('.input-prefix') ? input.closest('.input-prefix').querySelector('span').textContent : 'Time';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tw-open';
        btn.setAttribute('aria-label', 'Choose ' + label + ' time');
        btn.setAttribute('aria-haspopup', 'dialog');
        btn.innerHTML = '<span class="mi" aria-hidden="true">schedule</span>';
        btn.addEventListener('click', function (e) { open(input, btn, e.detail === 0); });   // detail 0: Enter / Space
        input.insertAdjacentElement('afterend', btn);
    });
})();
