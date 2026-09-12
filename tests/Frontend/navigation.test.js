import test from 'node:test';
import assert from 'node:assert/strict';
import { appUrl, setAppBasePath, messageHtml } from '../../resources/js/urls.js';
import { guardDraftNavigation } from '../../resources/js/draftNavigation.js';

test('page, API and query URLs preserve root and subfolder installations', () => {
    for (const base of ['', '/helpdesk/public', '/nested/support/']) {
        setAppBasePath(base);
        const prefix = base.replace(/\/$/, '');
        assert.equal(appUrl('/tickets/42'), prefix + '/tickets/42');
        assert.equal(appUrl('/api/v1/tickets/42/draft'), prefix + '/api/v1/tickets/42/draft');
        assert.equal(appUrl('/settings?section=accounts'), prefix + '/settings?section=accounts');
        assert.equal(appUrl({ path: '/tickets', query: { view: 'On hold', unused: null } }), prefix + '/tickets?view=On+hold');
        assert.equal(appUrl('/'), prefix + '/');
    }
    setAppBasePath('');
});

test('inline image display uses the app path without changing canonical message content or external links', () => {
    setAppBasePath('/helpdesk/public');
    const path = '/api/v1/inline-images/12345678-1234-1234-1234-123456789abc';
    const html = `<p>Picture: <img src="${path}" alt="Order" /></p><a href="https://example.com/order">Order</a>`;
    assert.equal(messageHtml(html), html.replace(`src="${path}"`, `src="/helpdesk/public${path}"`));
    assert.equal(messageHtml(messageHtml(html)), messageHtml(html));
    assert.ok(html.includes(`src="${path}"`));
    setAppBasePath('');
});

const settle = () => new Promise(resolve => setImmediate(resolve));
function fixture({ dirty = true, sending = false, save } = {}) {
    const state = { dirty, sending, visits: [], errors: [], saves: 0 };
    let before;
    const router = {
        on(name, callback) { assert.equal(name, 'before'); before = callback; return () => { before = null; }; },
        visit(url, options) { state.visits.push({ url, options }); },
    };
    state.remove = guardDraftNavigation(router, {
        isDirty: () => state.dirty, isSending: () => state.sending,
        save: async () => { state.saves++; if (save) await save(state); else state.dirty = false; },
        onError: message => state.errors.push(message),
    });
    state.navigate = (url, options = {}) => {
        const event = { detail: { visit: { url, method: 'get', ...options } }, prevented: false, preventDefault() { this.prevented = true; } };
        before?.(event);
        return event;
    };
    return state;
}

test('navigation saves dirty or cleared drafts before replaying the requested visit', async () => {
    const state = fixture();
    assert.equal(state.navigate('/helpdesk/public/logout', { method: 'post', data: {} }).prevented, true);
    assert.equal(state.visits.length, 0);
    await settle();
    assert.equal(state.saves, 1);
    assert.equal(state.visits[0].url, '/helpdesk/public/logout');
    assert.equal(state.visits[0].options.method, 'post');
    state.remove();
});

test('draft save failures keep the user on the ticket and permit a later retry', async () => {
    const state = fixture({ save: async state => { if (state.saves === 1) throw new Error('Network unavailable'); state.dirty = false; } });
    state.navigate('/tickets');
    await settle();
    assert.equal(state.visits.length, 0);
    assert.match(state.errors[0], /Couldn’t save your draft.*Network unavailable/);
    state.navigate('/tickets');
    await settle();
    assert.equal(state.visits.length, 1);
    state.remove();
});

test('rapid navigation keeps the latest destination and waits for edits made during saving', async () => {
    let finish;
    const state = fixture({ save: async state => {
        if (state.saves === 1) await new Promise(resolve => { finish = resolve; });
        else state.dirty = false;
    } });
    state.navigate('/tickets');
    state.navigate('/settings');
    finish();
    await settle();
    assert.equal(state.saves, 2);
    assert.deepEqual(state.visits.map(v => v.url), ['/settings']);
    state.remove();
});

test('navigation never races an outgoing reply and disposing the page cancels pending navigation', async () => {
    const sending = fixture({ sending: true });
    assert.equal(sending.navigate('/tickets').prevented, true);
    assert.equal(sending.saves, 0);
    assert.match(sending.errors[0], /finish sending/);
    sending.remove();
    let finish;
    const pending = fixture({ save: async state => { await new Promise(resolve => { finish = resolve; }); state.dirty = false; } });
    pending.navigate('/tickets');
    pending.remove();
    finish();
    await settle();
    assert.equal(pending.visits.length, 0);
    const clean = fixture({ dirty: false });
    assert.equal(clean.navigate('/tickets').prevented, false);
    assert.equal(clean.saves, 0);
    clean.remove();
});
