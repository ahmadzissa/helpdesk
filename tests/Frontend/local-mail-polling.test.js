import test from 'node:test';
import assert from 'node:assert/strict';
import { createLocalMailPoller } from '../../resources/js/localMailPolling.js';

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
