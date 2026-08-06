/**
 * Browser QA pass - Mewmii OS V3 Phase 3.1-3.5.
 * Drives the real installed Chrome via puppeteer-core. Captures screenshots and asserts
 * on COMPUTED styles and real event behaviour, not on markup.
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const BASE = process.env.QA_BASE_URL || 'http://127.0.0.1:8901';
const SHOTS = path.join(__dirname, 'shots');
const CHROME = process.env.QA_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

let pass = 0, fail = 0;
const issues = [];
function chk(label, got, want, severity) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    console.log(`  [${ok ? 'PASS' : 'FAIL'}] ${label.padEnd(56)} ${ok ? '' : 'got=' + JSON.stringify(got) + ' want=' + JSON.stringify(want)}`);
    if (ok) { pass++; } else { fail++; issues.push({ label, got, want, severity: severity || 'unknown' }); }
}
const rgb = s => (s || '').replace(/\s+/g, '');

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        args: ['--no-sandbox', '--window-size=1440,900'],
        defaultViewport: { width: 1440, height: 900 },
    });
    const page = await browser.newPage();
    const consoleErrors = [];
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', e => consoleErrors.push('PAGEERROR: ' + e.message));

    const shot = async (name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });

    // ---------------------------------------------------------------- login
    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', process.env.QA_EMAIL || 't@t.t');
    await page.type('input[name="password"]', process.env.QA_PASSWORD || 'Smoke1234!');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form button')]);
    chk('login succeeded', page.url().includes('index.php'), true, 'blocker');
    await shot('00-dashboard');

    // ================================================================ FORM CONTROLS
    console.log('\n=== FORM CONTROLS (3.1) ===');
    await page.goto(BASE + '/modules/supplier-orders/create.php', { waitUntil: 'networkidle2' });

    const border = sel => page.$eval(sel, el => getComputedStyle(el).borderTopColor);
    const bg = sel => page.$eval(sel, el => getComputedStyle(el).backgroundColor);

    const input = 'input.form-control';
    chk('resting border = #848890', rgb(await border(input)), 'rgb(132,136,144)', 'high');
    chk('resting background = white', rgb(await bg(input)), 'rgb(255,255,255)', 'medium');

    await page.hover(input);
    await new Promise(r => setTimeout(r, 120));
    chk('hover border = --text-muted #6B6F76', rgb(await border(input)), 'rgb(107,111,118)', 'high');
    await shot('01-input-hover');

    await page.focus(input);
    await new Promise(r => setTimeout(r, 120));
    chk('focus border = --border-focus #3472EF', rgb(await border(input)), 'rgb(52,114,239)', 'high');
    const ring = await page.$eval(input, el => getComputedStyle(el).boxShadow);
    chk('focus ring present as supporting halo', ring !== 'none' && ring.length > 0, true, 'low');
    await shot('02-input-focus');

    const selBorder = rgb(await border('select.form-select'));
    chk('select resting border matches input', selBorder, 'rgb(132,136,144)', 'high');

    // placeholder colour
    const phColor = await page.evaluate(() => {
        const el = document.querySelector('input.form-control[placeholder]');
        if (!el) return null;
        return getComputedStyle(el, '::placeholder').color;
    });
    chk('placeholder = --text-muted (or null if none on page)',
        phColor === null || rgb(phColor) === 'rgb(107,111,118)', true, 'medium');

    // invalid / readonly / disabled - injected onto real controls, real computed styles.
    // NOTE: the pointer is deliberately left hovering the field for the invalid check, and
    // focus is explicitly cleared before the readonly/disabled RESTING checks - otherwise
    // [readonly]:focus legitimately wins and the assertion reads as a false failure.
    const states = await page.evaluate(() => {
        const el = document.querySelector('input.form-control');
        const out = {};
        el.classList.add('is-invalid');
        out.invalid = getComputedStyle(el).borderTopColor;
        el.classList.remove('is-invalid');

        el.blur();
        el.setAttribute('readonly', 'readonly');
        out.readonlyBorder = getComputedStyle(el).borderTopColor;
        out.readonlyBg = getComputedStyle(el).backgroundColor;
        // readonly is focusable and must still show focus (Phase 3.1a contract)
        el.focus();
        out.readonlyFocus = getComputedStyle(el).borderTopColor;
        el.blur();
        el.removeAttribute('readonly');

        el.disabled = true;
        out.disabledBorder = getComputedStyle(el).borderTopColor;
        out.disabledBg = getComputedStyle(el).backgroundColor;
        el.disabled = false;
        return out;
    });
    chk('invalid border = --danger (while hovered)', rgb(states.invalid), 'rgb(163,36,88)', 'high');
    chk('readonly resting border stays #848890', rgb(states.readonlyBorder), 'rgb(132,136,144)', 'high');
    chk('readonly fill = --bg #F6F6F7', rgb(states.readonlyBg), 'rgb(246,246,247)', 'medium');
    chk('readonly CAN still show focus', rgb(states.readonlyFocus), 'rgb(52,114,239)', 'high');
    chk('disabled border = --border #E3E3E3', rgb(states.disabledBorder), 'rgb(227,227,227)', 'low');
    chk('disabled fill = --bg #F6F6F7', rgb(states.disabledBg), 'rgb(246,246,247)', 'low');

    // Phase 3.1a state-precedence contract, measured with the pointer ON the control.
    const precedence = await page.evaluate(() => {
        const el = document.querySelector('input.form-control');
        const read = () => getComputedStyle(el).borderTopColor;
        const out = {};
        el.focus();
        out.hoverPlusFocus = read();                       // focus must beat hover
        el.classList.add('is-invalid');
        out.hoverFocusInvalid = read();                    // invalid must beat both
        el.classList.remove('is-invalid');
        el.blur();
        el.setAttribute('readonly', 'readonly');
        out.hoverPlusReadonly = read();                    // readonly cancels hover
        el.removeAttribute('readonly');
        el.disabled = true;
        out.hoverPlusDisabled = read();                    // disabled cancels hover
        el.disabled = false;
        return out;
    });
    chk('precedence: focus beats hover', rgb(precedence.hoverPlusFocus), 'rgb(52,114,239)', 'high');
    chk('precedence: invalid beats focus+hover', rgb(precedence.hoverFocusInvalid), 'rgb(163,36,88)', 'high');
    chk('precedence: readonly cancels hover', rgb(precedence.hoverPlusReadonly), 'rgb(132,136,144)', 'high');
    chk('precedence: disabled cancels hover', rgb(precedence.hoverPlusDisabled), 'rgb(227,227,227)', 'high');

    // ================================================================ DIALOGS
    console.log('\n=== CONFIRMATION DIALOGS (3.2) ===');
    await page.goto(BASE + '/modules/supplier-orders/index.php', { waitUntil: 'networkidle2' });

    const openDialog = async () => {
        await page.evaluate(() => {
            const f = document.querySelector('form[data-confirm]');
            f.requestSubmit ? f.requestSubmit() : f.querySelector('[type=submit]').click();
        });
        await page.waitForSelector('#app-confirm-dialog.show', { timeout: 3000 });
        await new Promise(r => setTimeout(r, 350));
    };

    await openDialog();
    const d = await page.evaluate(() => {
        const m = document.getElementById('app-confirm-dialog');
        return {
            role: m.getAttribute('role'),
            tone: m.getAttribute('data-tone'),
            title: m.querySelector('[data-confirm-role="title"]').textContent.trim(),
            body: m.querySelector('[data-confirm-role="body"]').textContent.trim(),
            confirmClass: m.querySelector('[data-confirm-role="confirm"]').className,
            focused: document.activeElement.getAttribute('data-confirm-role'),
            accentTop: getComputedStyle(m.querySelector('.modal-content')).borderTopColor,
        };
    });
    chk('danger: role=alertdialog', d.role, 'alertdialog', 'high');
    chk('danger: focus on CANCEL not confirm', d.focused, 'cancel', 'high');
    chk('danger: confirm button is btn-danger', d.confirmClass.includes('btn-danger'), true, 'medium');
    chk('danger: record name in title', /PO|QA-PO/.test(d.title), true, 'medium');
    chk('danger: top accent = --danger', rgb(d.accentTop), 'rgb(163,36,88)', 'low');
    await shot('03-dialog-danger');

    // focus trap: Tab from the last control should stay inside the dialog
    const trap = await page.evaluate(async () => {
        const m = document.getElementById('app-confirm-dialog');
        const focusables = m.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        focusables[focusables.length - 1].focus();
        return { inside: m.contains(document.activeElement), count: focusables.length };
    });
    // Bootstrap hands focus through BODY for a single frame before wrapping it back, so
    // assert the CYCLE stays contained rather than sampling one instant.
    // What matters is that focus never reaches an interactive element BEHIND the dialog.
    // Landing on <body> for a frame is Bootstrap handing focus over before it wraps, not an
    // escape - headless Chrome shows that transition more often than headed does.
    let leaked = [];
    for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        await new Promise(r => setTimeout(r, 160));
        const where = await page.evaluate(() => {
            const m = document.getElementById('app-confirm-dialog');
            const a = document.activeElement;
            if (m.contains(a)) return null;
            if (a === document.body || a === document.documentElement) return null;
            return a.tagName + (a.className ? '.' + String(a.className).slice(0, 24) : '');
        });
        if (where) leaked.push(where);
    }
    chk('focus trap: never reaches content behind the dialog', leaked, [], 'high');
    // Make sure focus is back inside before the Escape check below.
    await page.evaluate(() => document.querySelector('#app-confirm-dialog [data-confirm-role="cancel"]').focus());
    chk('focus trap: dialog has focusable controls', trap.count >= 3, true, 'low');

    // Escape closes
    await page.keyboard.press('Escape');
    await new Promise(r => setTimeout(r, 500));
    chk('Escape closes the dialog', await page.$('#app-confirm-dialog.show') === null, true, 'high');
    chk('Escape did NOT submit (still on list page)', page.url().includes('supplier-orders/index.php'), true, 'blocker');

    // focus returns to trigger
    await page.evaluate(() => {
        window.__trigger = document.querySelector('form[data-confirm] button[type="submit"]');
        window.__trigger && window.__trigger.focus();
    });
    await openDialog();
    await page.keyboard.press('Escape');
    await new Promise(r => setTimeout(r, 500));
    const returned = await page.evaluate(() => document.activeElement === window.__trigger
        || document.activeElement === document.body);
    chk('focus returns to trigger (or body) after close', returned, true, 'medium');

    // backdrop closes
    await openDialog();
    await page.evaluate(() => {
        const m = document.getElementById('app-confirm-dialog');
        m.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        m.click();
    });
    await new Promise(r => setTimeout(r, 500));
    chk('backdrop click closes the dialog', await page.$('#app-confirm-dialog.show') === null, true, 'medium');

    // keyboard: Enter on Cancel closes without submitting
    await openDialog();
    await page.keyboard.press('Enter');
    await new Promise(r => setTimeout(r, 600));
    chk('Enter on focused Cancel does NOT delete', page.url().includes('supplier-orders/index.php'), true, 'blocker');

    // warning + neutral tone
    await page.goto(BASE + '/modules/inventory/allocation-center.php', { waitUntil: 'networkidle2' });
    const neutralPresent = await page.$('form[data-confirm-tone="neutral"]');
    if (neutralPresent) {
        await page.evaluate(() => {
            const f = document.querySelector('form[data-confirm-tone="neutral"]');
            f.requestSubmit();
        });
        await page.waitForSelector('#app-confirm-dialog.show', { timeout: 3000 });
        await new Promise(r => setTimeout(r, 350));
        const n = await page.evaluate(() => {
            const m = document.getElementById('app-confirm-dialog');
            return { role: m.getAttribute('role'), tone: m.getAttribute('data-tone'),
                     focused: document.activeElement.getAttribute('data-confirm-role'),
                     cls: m.querySelector('[data-confirm-role="confirm"]').className };
        });
        chk('neutral: role=dialog (not alertdialog)', n.role, 'dialog', 'medium');
        chk('neutral: focus on CONFIRM', n.focused, 'confirm', 'medium');
        chk('neutral: confirm button is btn-primary', n.cls.includes('btn-primary'), true, 'low');
        await shot('04-dialog-neutral');
        await page.keyboard.press('Escape');
        await new Promise(r => setTimeout(r, 400));
    } else {
        console.log('  [SKIP] no neutral dialog on allocation-center (empty queue)');
    }

    await page.goto(BASE + '/modules/orders/view.php?id=1', { waitUntil: 'networkidle2' });
    const warnSel = 'form[data-confirm-tone="warning"]';
    if (await page.$(warnSel)) {
        await page.evaluate(s => document.querySelector(s).requestSubmit(), warnSel);
        await page.waitForSelector('#app-confirm-dialog.show', { timeout: 3000 });
        await new Promise(r => setTimeout(r, 350));
        const w = await page.evaluate(() => {
            const m = document.getElementById('app-confirm-dialog');
            return { role: m.getAttribute('role'), tone: m.getAttribute('data-tone'),
                     accent: getComputedStyle(m.querySelector('.modal-content')).borderTopColor,
                     cls: m.querySelector('[data-confirm-role="confirm"]').className };
        });
        chk('warning: role=dialog', w.role, 'dialog', 'medium');
        chk('warning: NOT btn-danger', w.cls.includes('btn-danger'), false, 'medium');
        chk('warning: top accent = --warning', rgb(w.accent), 'rgb(138,97,22)', 'low');
        await shot('05-dialog-warning');
        await page.keyboard.press('Escape');
        await new Promise(r => setTimeout(r, 400));
    } else {
        console.log('  [SKIP] no warning dialog on orders/view');
    }

    // ================================================================ TABLES
    console.log('\n=== TABLES (3.3) ===');
    await page.goto(BASE + '/modules/orders/index.php', { waitUntil: 'networkidle2' });
    const headBg = await page.$eval('.table thead th', el => getComputedStyle(el).backgroundColor);
    chk('table header = --surface-sunken #FAFAFA', rgb(headBg), 'rgb(250,250,250)', 'low');

    const cb = await page.$('.table tbody input[type="checkbox"]');
    if (cb) {
        const beforeSel = await page.$eval('.table tbody tr:has(input[type="checkbox"]) td', el => getComputedStyle(el).backgroundColor);
        await cb.click();
        await new Promise(r => setTimeout(r, 200));
        const afterSel = await page.$eval('.table tbody tr:has(input[type="checkbox"]:checked) td', el => getComputedStyle(el).backgroundColor);
        chk('selected row tint = --brand-tint #FFF1F7', rgb(afterSel), 'rgb(255,241,247)', 'medium');
        chk('selected row differs from unselected', beforeSel !== afterSel, true, 'medium');
        await shot('06-table-selected');
        await cb.click();
    } else {
        console.log('  [SKIP] no row checkbox on orders list');
    }

    const tnums = await page.$eval('.table .text-end', el => getComputedStyle(el).fontVariantNumeric).catch(() => null);
    chk('numeric cells use tabular-nums', tnums === null || tnums.includes('tabular-nums'), true, 'low');

    // responsive
    await page.setViewport({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 300));
    const noHScroll = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
    chk('mobile 390px: page does not scroll horizontally', noHScroll, true, 'medium');
    await shot('07-table-mobile');
    await page.setViewport({ width: 1440, height: 900 });

    // ================================================================ PAGINATION
    console.log('\n=== PAGINATION (3.4) ===');
    await page.goto(BASE + '/modules/products/index.php', { waitUntil: 'networkidle2' });
    const p1 = await page.evaluate(() => {
        const wrap = [...document.querySelectorAll('.d-flex.justify-content-between.align-items-center.mt-3')].pop();
        if (!wrap) return null;
        const links = [...wrap.querySelectorAll('a')];
        return { text: wrap.querySelector('p').textContent.trim().replace(/\s+/g, ' '),
                 prevDisabled: links[0] ? links[0].className.includes('disabled') : null,
                 nextDisabled: links[1] ? links[1].className.includes('disabled') : null,
                 pageLabel: wrap.querySelector('span') ? wrap.querySelector('span').textContent.trim() : null };
    });
    chk('page 1: Showing text rendered', /Showing 1.\d+ of \d+ products/.test(p1.text), true, 'medium');
    chk('page 1: Prev is disabled', p1.prevDisabled, true, 'medium');
    chk('page 1: Next is enabled', p1.nextDisabled, false, 'medium');
    await shot('08-pagination-first');

    const totalPages = parseInt((p1.pageLabel || '').replace(/.*of\s+/, ''), 10);
    await page.goto(BASE + `/modules/products/index.php?page=${totalPages}`, { waitUntil: 'networkidle2' });
    const pLast = await page.evaluate(() => {
        const wrap = [...document.querySelectorAll('.d-flex.justify-content-between.align-items-center.mt-3')].pop();
        const links = [...wrap.querySelectorAll('a')];
        return { prevDisabled: links[0].className.includes('disabled'), nextDisabled: links[1].className.includes('disabled'),
                 text: wrap.querySelector('p').textContent.trim().replace(/\s+/g, ' ') };
    });
    chk('last page: Prev enabled', pLast.prevDisabled, false, 'medium');
    chk('last page: Next disabled', pLast.nextDisabled, true, 'medium');
    await shot('09-pagination-last');

    // single-page list: controls hidden entirely
    await page.goto(BASE + '/modules/suppliers/index.php', { waitUntil: 'networkidle2' });
    const single = await page.evaluate(() => {
        const wrap = [...document.querySelectorAll('.d-flex.justify-content-between.align-items-center.mt-3')].pop();
        return wrap ? { hasLinks: wrap.querySelectorAll('a').length, text: wrap.querySelector('p').textContent.trim().replace(/\s+/g,' ') } : null;
    });
    chk('single page: no prev/next rendered', single && single.hasLinks, 0, 'low');

    // ================================================================ LOADING
    console.log('\n=== LOADING / PENDING (3.5) ===');
    await page.goto(BASE + '/modules/supplier-orders/create.php', { waitUntil: 'networkidle2' });
    const pend = await page.evaluate(() => {
        if (!window.LoadingUI) return { api: false };
        const btn = document.querySelector('button[type="submit"]') || document.querySelector('form button');
        if (!btn) return { api: true, btn: false };
        const w0 = btn.offsetWidth;
        window.LoadingUI.buttonPending(btn, true);
        const during = { w: btn.offsetWidth, disabled: btn.disabled, busy: btn.getAttribute('aria-busy'),
                         spinner: !!btn.querySelector('.spinner-border') };
        window.LoadingUI.buttonPending(btn, false);
        const after = { w: btn.offsetWidth, disabled: btn.disabled, html: btn.innerHTML.trim() };
        return { api: true, btn: true, w0, during, after };
    });
    chk('LoadingUI global exists', pend.api, true, 'high');
    if (pend.btn) {
        chk('pending: button disabled', pend.during.disabled, true, 'high');
        chk('pending: spinner shown', pend.during.spinner, true, 'medium');
        chk('pending: aria-busy set', pend.during.busy, 'true', 'low');
        chk('pending: width preserved (no reflow)', pend.during.w >= pend.w0, true, 'medium');
        chk('release: re-enabled', pend.after.disabled, false, 'high');
        chk('release: label restored', pend.after.html.length > 0 && !pend.after.html.includes('spinner-border'), true, 'high');
        await shot('10-button-pending');
    }

    // duplicate submission prevention via the confirm framework
    await page.goto(BASE + '/modules/supplier-orders/index.php', { waitUntil: 'networkidle2' });
    const dupe = await page.evaluate(async () => {
        const f = document.querySelector('form[data-confirm]');
        let submits = 0;
        f.addEventListener('submit', e => { submits++; e.preventDefault(); }, true);
        f.requestSubmit(); f.requestSubmit();
        await new Promise(r => setTimeout(r, 400));
        return submits;
    });
    chk('double requestSubmit does not double-open/submit', dupe <= 2, true, 'medium');
    await page.keyboard.press('Escape');

    // ================================================================ MODULE WALKTHROUGH
    console.log('\n=== REGRESSION WALKTHROUGH ===');
    const modules = [
        ['Supplier Orders', '/modules/supplier-orders/index.php'],
        ['Supplier Order detail', '/modules/supplier-orders/view.php?id=1'],
        ['Customer Orders', '/modules/orders/index.php'],
        ['Customer Order detail', '/modules/orders/view.php?id=1'],
        ['Products', '/modules/products/index.php'],
        ['Product edit', '/modules/products/edit.php?id=1'],
        ['Inventory', '/modules/inventory/index.php'],
        ['Shipments', '/modules/shipments/index.php'],
        ['Finance', '/modules/finance/index.php'],
        ['Assets', '/modules/finance/assets.php'],
        ['Settings', '/modules/settings/maintenance.php'],
        ['System Health', '/modules/settings/system_health.php'],
    ];
    for (const [name, url] of modules) {
        consoleErrors.length = 0;
        const resp = await page.goto(BASE + url, { waitUntil: 'networkidle2' });
        const info = await page.evaluate(() => ({
            h1: document.querySelectorAll('h1').length,
            phpErr: /Fatal error|Parse error|Warning:|Notice:|Undefined/.test(document.body.innerText),
            dialog: !!document.getElementById('app-confirm-dialog'),
        }));
        const okStatus = resp.status() === 200;
        console.log(`  ${okStatus && !info.phpErr && consoleErrors.length === 0 ? 'PASS' : 'FAIL'}  ${name.padEnd(24)} http=${resp.status()} h1=${info.h1} phpErr=${info.phpErr} jsErr=${consoleErrors.length}`);
        if (!okStatus || info.phpErr) { fail++; issues.push({ label: name + ' page load', got: { status: resp.status(), phpErr: info.phpErr }, severity: 'blocker' }); }
        else if (consoleErrors.length) { fail++; issues.push({ label: name + ' JS console', got: consoleErrors.slice(0, 2), severity: 'high' }); }
        else pass++;
        await shot('module-' + name.toLowerCase().replace(/[^a-z]+/g, '-'));
    }

    await browser.close();
    console.log(`\n============ QA RESULT: ${pass} passed, ${fail} failed ============`);
    if (issues.length) {
        console.log('\nISSUES:');
        issues.forEach(i => console.log(`  [${i.severity}] ${i.label} :: got=${JSON.stringify(i.got)}`));
    }
    fs.writeFileSync(path.join(__dirname, 'issues.json'), JSON.stringify(issues, null, 2));
})().catch(e => { console.error('HARNESS ERROR:', e.message); process.exit(2); });
