import test from 'node:test';
import assert from 'node:assert/strict';
import { createTicketRefresher } from '../../resources/js/ticketRefresh.js';

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
