import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as vue from 'vue';
import { useTicketSearch } from '../../resources/js/useTicketSearch.js';

const { descriptor } = parse(readFileSync(new URL('../../resources/js/pages/Inbox.vue', import.meta.url), 'utf8'));
const compiled = compileScript(descriptor, { id: 'inbox-refresh-test', inlineTemplate: true }).content
    .replace(/^import\s+(.+?)\s+from\s+['"](.+?)['"];?$/gm, (_, bindings, source) => {
        const names = bindings.startsWith('{') ? bindings.replace(/\bas\b/g, ':') : `{ default: ${bindings} }`;
        return `const ${names} = modules[${JSON.stringify(source)}];`;
    }).replace('export default', 'return');
const component = new Function('modules', 'setInterval', 'clearInterval', compiled);
const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await vue.nextTick(); };
const ticket = (id, subject = `Conversation ${id}`) => ({ id, subject, requester_email: 'customer@example.test', status: 'Open', last_activity_at: '2026-09-13T00:00:00Z', tags: [] });
const response = (tickets, lastPage = 1) => ({ tickets, total: tickets.length, last_page: lastPage });

function mount() {
    const requests = [], root = { children: [] };
    const state = vue.reactive({ scope: 'all', refresh: 0, user: { role: 'admin' }, workspace: { views: [], statuses: [], priorities: [], agents: [], teams: [] } });
    const route = vue.reactive({ query: {} });
    const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null });
    function remove(child) {
        if (!child.parent) return;
        const index = child.parent.children.indexOf(child);
        if (index !== -1) child.parent.children.splice(index, 1);
        child.parent = null;
    }
    const renderer = vue.createRenderer({
        createElement: type => node(type), createText: text => node('text', text), createComment: text => node('comment', text),
        setText: (element, text) => { element.text = text; },
        setElementText: (element, text) => { element.children = []; element.text = text; },
        patchProp: (element, key, previous, value) => { element.props[key] = value; },
        parentNode: element => element.parent,
        nextSibling: element => element.parent?.children[element.parent.children.indexOf(element) + 1],
        remove,
        insert(child, parent, anchor = null) {
            remove(child);
            const index = anchor ? parent.children.indexOf(anchor) : -1;
            parent.children.splice(index === -1 ? parent.children.length : index, 0, child);
            child.parent = parent;
        },
    });
    const stub = { render: () => null };
    let poll;
    const inbox = component({
        vue: { ...vue, vModelText: {}, vModelCheckbox: {}, vModelSelect: {} },
        '../useNavigation': { useNavigation: () => route },
        '../store': { state, api: path => new Promise((resolve, reject) => requests.push({ path, resolve, reject })), notify() {}, initials: () => 'C', relativeTime: () => 'now', statusClass: value => value.toLowerCase() },
        '../components/Modal.vue': { default: stub },
        '../components/TicketRowMenu.vue': { default: stub },
        '../useBulkTicketDeletion': { useBulkTicketDeletion: () => ({ deleting: vue.ref(null), error: vue.ref(''), open() {}, confirm() {} }) },
        '../useTicketSearch': { useTicketSearch },
    }, callback => { poll = callback; return 1; }, () => {});
    const app = renderer.createApp(inbox);
    app.provide('newTicket', () => {});
    app.component('Icon', stub);
    app.component('Link', { setup: (props, { slots }) => () => vue.h('a', slots.default?.()) });
    app.config.globalProperties.$appUrl = path => path;
    app.mount(root);
    function find(predicate, element = root) {
        return [...(predicate(element) ? [element] : []), ...element.children.flatMap(child => find(predicate, child))];
    }
    const rows = () => find(element => element.props?.class?.split(' ').includes('ticket-row'));
    const skeletons = () => find(element => element.props?.class === 'skeleton-list');
    const loadMore = () => find(element => element.props?.class === 'load-more secondary-button')[0];
    return { requests, state, route, rows, skeletons, loadMore, find, poll: () => poll(), stop: () => app.unmount() };
}

test('mail sync keeps mounted rows and selection while new tickets and status changes arrive', async () => {
    const mounted = mount();
    try {
        assert.equal(mounted.skeletons().length, 1);
        mounted.requests[0].resolve(response([ticket(1)]));
        await flush();
        const row = mounted.rows()[0];
        const checkbox = mounted.find(element => element.props?.['aria-label'] === 'Select ticket 1')[0];
        checkbox.props['onUpdate:modelValue']([1]);
        mounted.state.refresh++;
        await flush();
        assert.equal(mounted.skeletons().length, 0);
        assert.equal(mounted.rows()[0], row);
        mounted.requests[1].resolve(response([ticket(2), { ...ticket(1), status: 'Pending' }]));
        await flush();
        assert.equal(mounted.rows()[1], row);
        assert.match(row.props.class, /selected/);
        assert.equal(mounted.rows().length, 2);
        assert.ok(mounted.find(element => element.text === 'Pending').length);
    } finally { mounted.stop(); }
});

test('refreshing all loaded pages applies one update and keeps existing rows on failure', async () => {
    const mounted = mount();
    try {
        mounted.requests[0].resolve(response([ticket(1)], 3));
        await flush();
        mounted.loadMore().props.onClick();
        mounted.requests[1].resolve(response([ticket(2)], 3));
        await flush();
        const rows = mounted.rows();
        mounted.state.refresh++;
        await flush();
        mounted.requests[2].resolve(response([ticket(1, 'Updated subject')], 3));
        await flush();
        assert.match(mounted.requests[3].path, /page=2/);
        assert.deepEqual(mounted.rows(), rows);
        mounted.requests[3].reject(new Error('Connection interrupted'));
        await flush();
        assert.deepEqual(mounted.rows(), rows);
        assert.equal(mounted.skeletons().length, 0);
        assert.equal(mounted.find(element => element.props?.role === 'alert').length, 1);
        mounted.state.refresh++;
        await flush();
        mounted.requests[4].resolve(response([ticket(1, 'Updated subject')], 3));
        await flush();
        mounted.requests[5].resolve(response([ticket(2)], 3));
        await flush();
        assert.deepEqual(mounted.rows(), rows);
        assert.ok(mounted.find(element => element.text === 'Updated subject').length);
        assert.equal(mounted.find(element => element.props?.role === 'alert').length, 0);
    } finally { mounted.stop(); }
});

test('changing views discards older refresh responses and a failed load more retries the same page', async () => {
    const mounted = mount();
    try {
        mounted.requests[0].resolve(response([ticket(1)], 3));
        await flush();
        mounted.loadMore().props.onClick();
        mounted.requests[1].reject(new Error('Offline'));
        await flush();
        mounted.loadMore().props.onClick();
        assert.match(mounted.requests[2].path, /page=2/);
        mounted.requests[2].resolve(response([ticket(2)], 3));
        await flush();
        mounted.state.refresh++;
        await flush();
        mounted.route.query.view = 'Pending';
        await flush();
        mounted.requests[4].resolve(response([ticket(3)]));
        await flush();
        const row = mounted.rows()[0];
        mounted.requests[3].resolve(response([ticket(1)], 3));
        await flush();
        assert.equal(mounted.rows().length, 1);
        assert.equal(mounted.rows()[0], row);
        assert.ok(mounted.find(element => element.text === 'Conversation 3').length);
    } finally { mounted.stop(); }
});
