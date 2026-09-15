import test from 'node:test';
import assert from 'node:assert/strict';
import { createTicketRefresher } from '../../resources/js/ticketRefresh.js';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as vue from 'vue';
import * as cannedReplies from '../../resources/js/cannedReplies.js';
import * as ticketTimeline from '../../resources/js/ticketTimeline.js';

const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const tick = () => new Promise(resolve => setImmediate(resolve));

test('incoming-only conversations update and simultaneous triggers share one request', async () => {
    const response = deferred(), updates = [];
    let requests = 0;
    const poller = createTicketRefresher({ getId: () => 1, fetchTicket: () => { requests++; return response.promise; }, apply: result => updates.push(result) });
    const timer = poller.refresh(), sync = poller.refresh();
    await tick();
    assert.equal(requests, 1);
    response.resolve({ ticket: { id: 1, messages: [{ id: 1, kind: 'inbound' }, { id: 2, kind: 'inbound' }] } });
    await Promise.all([timer, sync]);
    assert.equal(updates.length, 1);
    assert.equal(updates[0].ticket.messages.at(-1).id, 2);
});

test('a reply or ticket change invalidates an older response without blocking the next refresh', async () => {
    const old = deferred(), current = deferred(), updates = [];
    let requests = 0;
    const poller = createTicketRefresher({ getId: () => 1, fetchTicket: () => ++requests === 1 ? old.promise : current.promise, apply: result => updates.push(result) });
    const first = poller.refresh();
    await tick();
    poller.invalidate();
    const next = poller.refresh();
    await tick();
    current.resolve('new reply');
    await next;
    old.resolve('stale conversation');
    await first;
    assert.deepEqual(updates, ['new reply']);
});

test('leaving or disposing a ticket discards in-flight data and failures allow retries', async () => {
    let id = 1;
    const response = deferred(), updates = [];
    const poller = createTicketRefresher({ getId: () => id, fetchTicket: () => response.promise, apply: result => updates.push(result) });
    const request = poller.refresh();
    id = 2;
    response.resolve('previous ticket');
    await request;
    assert.deepEqual(updates, []);
    poller.dispose();
    await poller.refresh();
    assert.deepEqual(updates, []);
    let count = 0;
    const retry = createTicketRefresher({ getId: () => 1, fetchTicket: async () => { if (++count === 1) throw new Error('offline'); return 'reconnected'; }, apply: result => updates.push(result) });
    await assert.rejects(retry.refresh(), /offline/);
    await retry.refresh();
    assert.deepEqual(updates, ['reconnected']);
});

test('the open ticket polls new messages without replacing the draft and retries after a failed refresh', async () => {
    const { descriptor } = parse(readFileSync(new URL('../../resources/js/pages/Ticket.vue', import.meta.url), 'utf8'));
    const compiled = compileScript(descriptor, { id: 'ticket-refresh-test' }).content
        .replace(/^import\s+(.+?)\s+from\s+['"](.+?)['"];?$/gm, (_, bindings, source) => {
            const names = bindings.startsWith('{') ? bindings.replace(/\bas\b/g, ':') : `{ default: ${bindings} }`;
            return `const ${names} = modules[${JSON.stringify(source)}];`;
        }).replace('export default', 'return');
    const requests = [], windowListeners = new Map(), documentListeners = new Map();
    const window = { innerWidth: 1200, addEventListener: (name, callback) => windowListeners.set(name, callback), removeEventListener: name => windowListeners.delete(name) };
    const document = { hidden: false, addEventListener: (name, callback) => documentListeners.set(name, callback), removeEventListener: name => documentListeners.delete(name) };
    const response = count => ({ ticket: { id: 1, subject: 'Order question', status: 'Open', messages: Array.from({ length: count }, (_, index) => ({ id: index + 1, kind: 'inbound' })) }, related: [], activity: [], draft: { body: 'Saved draft' } });
    let poll, interval;
    const modules = new Proxy({
        vue,
        '@inertiajs/vue3': { router: {} },
        '../useNavigation': { useNavigation: () => ({ params: { id: 1 }, query: {} }) },
        '../ticketRefresh': { createTicketRefresher },
        '../cannedReplies': cannedReplies,
        '../ticketTimeline': ticketTimeline,
        '../draftNavigation': { guardDraftNavigation: () => () => {} },
        '../store': { state: vue.reactive({ refresh: 0, workspace: { mailboxes: [], replies: [], settings: {} } }), api: (path, options) => new Promise((resolve, reject) => requests.push({ path, options, resolve, reject })) },
        '../useTicketTranslation': { useTicketTranslation: () => ({ enabled: vue.ref(false), settings: vue.ref({}), original: {}, errors: {}, pending: {}, preview: vue.ref(null), previewReady: vue.ref(false), autoReply: vue.ref(false), automaticSend: vue.ref(false) }) },
    }, { get: (target, name) => target[name] || {} });
    const component = new Function('modules', 'window', 'document', 'setInterval', 'clearInterval', compiled)(modules, window, document, (callback, delay) => { poll = callback; interval = delay; return 1; }, () => {});
    const renderer = vue.createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {} });
    component.render = () => null;
    const app = renderer.createApp(component);
    const instance = app.mount({}), state = instance.$.setupState;
    try {
        requests[0].resolve(response(1));
        await tick(); await new Promise(resolve => setTimeout(resolve, 5));
        assert.equal(interval, 30000);
        state.body = 'My unsent reply';
        await vue.nextTick();
        poll(); await tick();
        assert.equal(requests[1].options.cache, 'no-store');
        assert.ok(requests[1].options.signal instanceof AbortSignal);
        requests[1].resolve(response(2));
        await tick();
        assert.equal(state.ticket.messages.length, 2);
        assert.equal(state.body, 'My unsent reply');
        windowListeners.get('focus')(); await tick();
        requests[2].reject(new DOMException('Timed out', 'TimeoutError'));
        await tick();
        assert.match(state.refreshError, /Retrying automatically/);
        windowListeners.get('online')(); await tick();
        requests[3].resolve(response(3));
        await tick();
        assert.equal(state.ticket.messages.length, 3);
        assert.equal(state.refreshError, '');
        assert.equal(state.body, 'My unsent reply');
        document.hidden = true;
        poll(); await tick();
        assert.equal(requests.length, 4);
        document.hidden = false;
        documentListeners.get('visibilitychange')(); await tick();
        requests[4].resolve(response(4));
        await tick();
        assert.equal(state.ticket.messages.length, 4);
    } finally {
        state.sending = true;
        app.unmount();
    }
    assert.equal(windowListeners.size, 0);
    assert.equal(documentListeners.size, 0);
});
