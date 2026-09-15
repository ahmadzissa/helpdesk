import test from 'node:test';
import assert from 'node:assert/strict';
import { cannedShortcuts, matchesCannedShortcut, cannedShortcutTrigger, filterCannedReplies } from '../../resources/js/cannedReplies.js';
import { replyEditorHtml, normalizeImagePaste } from '../../resources/js/replyEditor.js';
import { messageHtml, setAppBasePath } from '../../resources/js/urls.js';

test('each comma-separated shortcut recalls the same response, including spaces and mixed case', () => {
    const reply = { shortcut: '#export reviews, #CSV file' };
    assert.deepEqual(cannedShortcuts(reply.shortcut), ['#export reviews', '#CSV file']);
    assert.ok(matchesCannedShortcut(reply, '#export reviews'));
    assert.ok(matchesCannedShortcut(reply, ' #csv   FILE '));
    assert.ok(matchesCannedShortcut({ shortcut: '#hello' }, '#hello'));
    assert.equal(matchesCannedShortcut(reply, '#CSV'), false);
    assert.equal(matchesCannedShortcut(reply, 'unrelated #CSV file'), false);
});

test('saved and pasted canned images render inline under the application base path', () => {
    const path = '/api/v1/canned-images/3d4e9de1-0187-4887-9c77-1b004f3d98a4';
    const markdown = `![Image](${path})`;
    setAppBasePath('/helpdesk/public');
    try {
        const html = replyEditorHtml('Before\n' + markdown + '\nAfter');
        assert.ok(html.includes(`src="/helpdesk/public${path}"`));
        assert.ok(html.includes(`data-markdown="${markdown}"`));
        assert.equal(normalizeImagePaste('\\' + markdown + '&#x20;'), markdown + ' ');
        assert.equal(messageHtml(`<img src="${path}">`), `<img src="/helpdesk/public${path}">`);
    } finally { setAppBasePath(''); }
});

test('typing # opens suggestions and each added letter filters titles and all aliases', () => {
    const replies = [{ title: 'Export instructions', shortcut: '#export reviews, #CSV file' }, { title: 'Welcome', shortcut: '#hello' }];
    assert.deepEqual(cannedShortcutTrigger('#', 1), { start: 0, end: 1, query: '' });
    assert.deepEqual(filterCannedReplies(replies, ''), replies);
    assert.deepEqual(filterCannedReplies(replies, 'csv f'), [replies[0]]);
    assert.deepEqual(filterCannedReplies(replies, 'welcome'), [replies[1]]);
    assert.deepEqual(filterCannedReplies(replies, 'missing'), []);
    const body = 'Hello\n#CSV fi more text';
    assert.deepEqual(cannedShortcutTrigger(body, 13), { start: 6, end: 13, query: 'csv fi' });
    const trigger = cannedShortcutTrigger(body, 13);
    assert.equal(body.slice(0, trigger.start) + 'Inserted reply' + body.slice(trigger.end), 'Hello\nInserted reply more text');
    assert.equal(cannedShortcutTrigger('https://example.com/#anchor', 27), null);
    assert.equal(cannedShortcutTrigger('name#hello', 10), null);
    assert.equal(cannedShortcutTrigger('#hello\nother line', 17), null);
});
