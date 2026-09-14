import test from 'node:test';
import assert from 'node:assert/strict';
import { replyLink, automationAction } from '../../resources/js/replyFormatting.js';

test('reply links preserve URL punctuation and escape selected Markdown text', () => {
    assert.equal(replyLink('Read [this]', 'https://example.com/a_(b)?x=1&y=2'), '[Read \\[this\\]](<https://example.com/a_(b)?x=1&y=2>)');
    assert.equal(replyLink('', 'mailto:support@example.com'), '[mailto:support@example.com](<mailto:support@example.com>)');
});

test('link insertion rejects unsafe protocols and malformed destinations', () => {
    for (const url of ['javascript:alert(1)', 'data:text/html,test', '/relative', 'https://example.com/a b', 'https://example.com/\nnext', 'https://example.com/<script>']) {
        assert.throws(() => replyLink('Link', url));
    }
});

test('automation timeline never describes held or unsuccessful replies as sent', () => {
    for (const delivery of ['held', 'translation_pending', 'failed', 'saved', 'suppressed']) {
        assert.ok(!automationAction({ kind: 'outbound', delivery }).startsWith('sent'));
    }
    assert.equal(automationAction({ kind: 'outbound', delivery: 'sent' }), 'sent a message');
    assert.equal(automationAction({ kind: 'note' }), 'added a private note');
});
