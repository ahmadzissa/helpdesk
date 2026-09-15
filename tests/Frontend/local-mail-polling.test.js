import test from 'node:test';
import assert from 'node:assert/strict';
import { createLocalMailPoller } from '../../resources/js/localMailPolling.js';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as vue from 'vue';

function fixture(request = async () => ({ checked: 1, errors: [] })) {
    const state = { enabled: true, visible: true, refreshes: 0, errors: [] };
    const poller = createLocalMailPoller({ enabled: () => state.enabled, visible: () => state.visible, request, refreshed: () => state.refreshes++, failed: message => state.errors.push(message) });
    return { state, poller };
}

test('opening a visible local app checks mail and refreshes tickets only after completed checks', async () => {
    let calls = 0;
    const { state, poller } = fixture(async () => ({ checked: ++calls === 1 ? 1 : 0, errors: [] }));
    await poller.poll();
    assert.equal(state.refreshes, 1);
    await poller.poll();
    assert.equal(state.refreshes, 1);
    state.visible = false;
    await poller.poll();
    state.visible = true;
    state.enabled = false;
    await poller.poll();
    assert.equal(calls, 2);
});

test('overlapping timers cannot start multiple requests and disposal prevents late updates', async () => {
    let finish, calls = 0;
    const { state, poller } = fixture(() => { calls++; return new Promise(resolve => { finish = resolve; }); });
    const running = poller.poll();
    await poller.poll();
    assert.equal(calls, 1);
    poller.dispose();
    finish({ checked: 1 });
    await running;
    await poller.poll();
    assert.equal(state.refreshes, 0);
    assert.equal(calls, 1);
});

test('connection errors stay visible without repeated notifications and healthy accounts still refresh', async () => {
    let fail = true;
    const { state, poller } = fixture(async () => ({ checked: 1, errors: fail ? [{ message: 'Check the account connection.' }] : [] }));
    await poller.poll();
    await poller.poll();
    assert.equal(state.refreshes, 2);
    assert.deepEqual(state.errors, ['Check the account connection.']);
    fail = false;
    await poller.poll();
    fail = true;
    await poller.poll();
    assert.equal(state.errors.length, 2);
});

test('failed HTTP requests can retry and logging out prevents late notifications', async () => {
    let fail = true;
    const { state, poller } = fixture(async () => { if (fail) throw new Error('Connection unavailable'); return { checked: 1 }; });
    await poller.poll();
    assert.deepEqual(state.errors, ['Connection unavailable']);
    fail = false;
    await poller.poll();
    assert.equal(state.refreshes, 1);
    state.enabled = false;
    await poller.poll();
    assert.equal(state.refreshes, 1);
});

test('the live app checks mail immediately on opening and All tickets, then every thirty seconds', async () => {
    const { descriptor } = parse(readFileSync(new URL('../../resources/js/App.vue', import.meta.url), 'utf8'));
    const compiled = compileScript(descriptor, { id: 'app-mail-polling-test' }).content
        .replace(/^import\s+(.+?)\s+from\s+['"](.+?)['"];?$/gm, (_, bindings, source) => {
            const names = bindings.startsWith('{') ? bindings.replace(/\bas\b/g, ':') : `{ default: ${bindings} }`;
            return `const ${names} = modules[${JSON.stringify(source)}];`;
        }).replace('export default', 'return');
    const requests = [], visits = [], timers = new Map(), windowListeners = new Map(), documentListeners = new Map();
    const state = vue.reactive({ user: { id: 1 }, refresh: 0, scope: 'all', workspace: { mailboxes: [], settings: {}, local_mail_polling: false } });
    const route = vue.reactive({ path: '/tickets/1', params: {}, query: {}, fullPath: '/tickets/1' });
    const document = { hidden: false, addEventListener: (name, callback) => documentListeners.set(name, callback), removeEventListener: name => documentListeners.delete(name) };
    const window = { addEventListener: (name, callback) => windowListeners.set(name, callback), removeEventListener: name => windowListeners.delete(name) };
    const modules = new Proxy({
        vue,
        '@inertiajs/vue3': { usePage: () => ({ props: {} }), router: { visit: url => visits.push(url) } },
        './useNavigation': { useNavigation: () => route },
        './urls': { appUrl: value => value },
        './store': { state, syncPage() {}, notify() {}, refreshSidebar: async () => {}, refreshSendingSafety: async () => {}, api: path => new Promise(resolve => requests.push({ path, resolve })) },
        './localMailPolling': { createLocalMailPoller },
    }, { get: (target, name) => target[name] || {} });
    const component = new Function('modules', 'window', 'document', 'setInterval', 'clearInterval', compiled)(modules, window, document, (callback, delay) => { timers.set(delay, callback); return delay; }, id => timers.delete(id));
    component.render = () => null;
    const renderer = vue.createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {} });
    const app = renderer.createApp(component), instance = app.mount({});
    const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await vue.nextTick(); };
    try {
        assert.equal(requests.length, 1);
        assert.equal(requests[0].path, 'mailboxes/poll');
        requests[0].resolve({ queued: 1 });
        await flush();
        assert.equal(state.refresh, 1);
        instance.$.setupState.goView('all');
        assert.equal(requests.length, 2);
        assert.equal(visits[0].path, '/tickets');
        requests[1].resolve({ checked: 1 });
        await flush();
        assert.equal(state.refresh, 2);
        route.path = '/tickets'; route.fullPath = '/tickets';
        await flush();
        assert.equal(requests.length, 3);
        requests[2].resolve({ checked: 0 });
        await flush();
        assert.ok(timers.has(30000));
        timers.get(30000)();
        assert.equal(requests.length, 4);
        requests[3].resolve({ checked: 0 });
        await flush();
        windowListeners.get('focus')();
        assert.equal(requests.length, 5);
        requests[4].resolve({ checked: 0 });
        await flush();
    } finally { app.unmount(); }
    assert.equal(timers.size, 0);
    assert.equal(windowListeners.size, 0);
    assert.equal(documentListeners.size, 0);
});
