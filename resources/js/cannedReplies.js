export function cannedShortcuts(value = '') {
    return value.split(',').map(shortcut => '#' + shortcut.trim().replace(/^#+/, '').replace(/\s+/gu, ' ')).filter(shortcut => shortcut !== '#');
}

export function matchesCannedShortcut(reply, text) {
    const normalized = text.trim().replace(/\s+/gu, ' ').toLowerCase();
    return cannedShortcuts(reply.shortcut).some(shortcut => shortcut.toLowerCase() === normalized);
}

export function cannedShortcutTrigger(body, caret) {
    const prefix = body.slice(0, caret);
    const match = prefix.match(/(?:^|\s)#([^#\r\n]*)$/u);
    if (!match) return null;
    return { start: prefix.lastIndexOf('#'), end: caret, query: match[1].trim().replace(/\s+/gu, ' ').toLowerCase() };
}

export function filterCannedReplies(replies, query) {
    return replies.filter(reply => [reply.title, ...cannedShortcuts(reply.shortcut)]
        .some(value => value.toLowerCase().includes(query)));
}
