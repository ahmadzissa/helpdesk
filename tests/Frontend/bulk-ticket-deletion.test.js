import test from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, nextTick, ref } from 'vue';

globalThis.document = { querySelector: () => ({ content: 'synthetic-csrf-token' }) };
const { useBulkTicketDeletion } = await import('../../resources/js/useBulkTicketDeletion.js');

function fixture(request) {
    const scope = effectScope(), selected = ref([7, 9]), busy = ref(false), completed = [];
    const deletion = scope.run(() => useBulkTicketDeletion(selected, busy, async result => completed.push(result), request));
    return { selected, busy, completed, deletion, stop: () => scope.stop() };
}

test('opening or cancelling the dialog does not delete tickets; confirmation sends exactly the selected IDs', async () => {
    const requests = [];
    const f = fixture(async (path, options) => { requests.push({ path, options }); return { deleted: 2 }; });
    try {
        f.deletion.open();
        assert.deepEqual(f.deletion.deleting.value, [7, 9]);
        assert.equal(requests.length, 0);
        f.deletion.deleting.value = null;
        assert.equal(await f.deletion.confirm(), false);
        assert.equal(requests.length, 0);
        f.deletion.open();
        assert.equal(await f.deletion.confirm(), true);
        assert.deepEqual(requests, [{ path: 'tickets/bulk', options: { method: 'DELETE', body: { ids: [7, 9], confirmed: true } } }]);
        assert.equal(f.deletion.deleting.value, null);
        assert.deepEqual(f.completed, [{ deleted: 2 }]);
        assert.equal(f.busy.value, false);
    } finally { f.stop(); }
});

test('changing the selection cancels a pending confirmation and an empty selection cannot be deleted', async () => {
    let calls = 0;
    const f = fixture(async () => { calls++; return { deleted: 1 }; });
    try {
        f.deletion.open();
        f.selected.value = [11];
        await nextTick();
        assert.equal(f.deletion.deleting.value, null);
        assert.equal(await f.deletion.confirm(), false);
        f.selected.value = [];
        await nextTick();
        f.deletion.open();
        assert.equal(f.deletion.deleting.value, null);
        assert.equal(calls, 0);
    } finally { f.stop(); }
});

test('a failed deletion keeps its confirmation and error visible without clearing the selection', async () => {
    const f = fixture(async () => { throw new Error('The selected tickets changed.'); });
    try {
        f.deletion.open();
        assert.equal(await f.deletion.confirm(), false);
        assert.equal(f.deletion.error.value, 'The selected tickets changed.');
        assert.deepEqual(f.deletion.deleting.value, [7, 9]);
        assert.deepEqual(f.selected.value, [7, 9]);
        assert.equal(f.busy.value, false);
        assert.deepEqual(f.completed, []);
    } finally { f.stop(); }
});

test('repeated confirmation clicks cannot send duplicate deletion requests', async () => {
    let finish, calls = 0;
    const f = fixture(async () => { calls++; return new Promise(resolve => { finish = resolve; }); });
    try {
        f.deletion.open();
        const first = f.deletion.confirm();
        assert.equal(f.busy.value, true);
        assert.equal(await f.deletion.confirm(), false);
        assert.equal(calls, 1);
        finish({ deleted: 2 });
        assert.equal(await first, true);
        assert.equal(f.busy.value, false);
        assert.deepEqual(f.completed, [{ deleted: 2 }]);
    } finally { f.stop(); }
});
