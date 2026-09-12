import test from 'node:test';
import assert from 'node:assert/strict';
import { createRenderer, ref, nextTick } from 'vue';
import { useInlineCustomFields } from '../../resources/js/useInlineCustomFields.js';

const renderer = createRenderer({ createElement: () => ({}), createText: () => ({}), createComment: () => ({}), insert() {}, remove() {}, setText() {}, setElementText() {}, parentNode() {}, nextSibling() {}, patchProp() {} });
const deferred = () => { let resolve; const promise = new Promise(yes => { resolve = yes; }); return { promise, resolve }; };
function mount(persist = async () => {}) {
    const ticket = ref({ id: 1, custom_field_definitions: [{ key: 'order', name: 'Order number' }, { key: 'store', name: 'Store URL' }], custom_fields: { order: '100', store: '' } });
    const calls = [];
    let fields;
    const app = renderer.createApp({ setup() {
        fields = useInlineCustomFields(() => ticket.value, async (key, value) => {
            calls.push({ key, value });
            await persist(key, value);
            ticket.value = { ...ticket.value, custom_fields: { ...ticket.value.custom_fields, [key]: value } };
        });
        return () => null;
    } });
    app.mount({});
    return { ticket, calls, fields, stop: () => app.unmount() };
}

test('clicking and typing in an inline field survives polling and saves only that field', async () => {
    const mounted = mount();
    try {
        const { ticket, fields, calls } = mounted;
        fields.edit('order', '200');
        ticket.value = { ...ticket.value, custom_fields: { order: '100', store: 'new.example.com' } };
        await nextTick();
        assert.equal(fields.values.order, '200');
        assert.equal(fields.values.store, 'new.example.com');
        await fields.save('order');
        assert.deepEqual(calls, [{ key: 'order', value: '200' }]);
        fields.edit('order', '');
        await fields.save('order');
        assert.equal(ticket.value.custom_fields.order, '');
        assert.equal(ticket.value.custom_fields.store, 'new.example.com');
    } finally { mounted.stop(); }
});

test('saving errors preserve the typed value for retry and repeated blurs share the pending save', async () => {
    const response = deferred();
    let attempts = 0;
    const mounted = mount(async () => { if (++attempts === 1) throw new Error('Connection lost'); await response.promise; });
    try {
        const { fields, ticket } = mounted;
        fields.edit('order', '200');
        await fields.save('order');
        assert.equal(fields.errors.order, 'Connection lost');
        ticket.value = { ...ticket.value, custom_fields: { order: '100' } };
        await nextTick();
        assert.equal(fields.values.order, '200');
        const first = fields.save('order'), second = fields.save('order');
        assert.equal(first, second);
        response.resolve();
        await first;
        assert.equal(attempts, 2);
        assert.equal(fields.errors.order, undefined);
        assert.equal(fields.saving.order, false);
    } finally { mounted.stop(); }
});

test('Escape cancels an edit and merged tickets cannot save field values', async () => {
    const mounted = mount();
    try {
        mounted.fields.edit('order', 'different');
        mounted.fields.reset('order');
        await mounted.fields.save('order');
        assert.equal(mounted.fields.values.order, '100');
        assert.equal(mounted.calls.length, 0);
        mounted.ticket.value.merged_into_id = 2;
        mounted.fields.edit('order', 'blocked');
        await mounted.fields.save('order');
        assert.equal(mounted.calls.length, 0);
    } finally { mounted.stop(); }
});
