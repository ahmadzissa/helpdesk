import test from 'node:test';
import assert from 'node:assert/strict';
import { ticketTimeline } from '../../resources/js/ticketTimeline.js';

test('activity is interleaved between messages without changing the source arrays', () => {
    const messages = [{ id: 2, created_at: '2026-09-14T12:00:00Z' }, { id: 1, created_at: '2026-09-14T10:00:00Z' }];
    const activities = [{ id: 3, created_at: '2026-09-14T13:00:00Z' }, { id: 1, created_at: '2026-09-14T11:00:00Z' }];
    assert.deepEqual(ticketTimeline(messages, activities).map(row => row.key), ['message-1', 'activity-1', 'message-2', 'activity-3']);
    assert.deepEqual(messages.map(message => message.id), [2, 1]);
    assert.deepEqual(activities.map(entry => entry.id), [3, 1]);
});

test('events created in the same second appear after the message in stable order', () => {
    const created_at = '2026-09-14T10:00:00Z';
    const rows = ticketTimeline([{ id: 7, created_at }], [{ id: 9, created_at }, { id: 7, created_at }]);
    assert.deepEqual(rows.map(row => row.key), ['message-7', 'activity-7', 'activity-9']);
    assert.equal(rows[0].entry, null);
    assert.equal(rows[1].message, null);
});

test('activity still appears on tickets without messages', () => {
    assert.deepEqual(ticketTimeline(), []);
    assert.equal(ticketTimeline([], [{ id: 1, created_at: '2026-09-14T10:00:00Z' }])[0].key, 'activity-1');
});
