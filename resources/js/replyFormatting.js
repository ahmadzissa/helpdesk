export function replyFormat(text, before, after = before) {
    const selected = text || 'text';
    const leading = selected.match(/^\s*/)[0], trailing = selected.match(/\s*$/)[0];
    const content = selected.trim();
    return content ? leading + before + content + after + trailing : selected;
}

export function replyLink(label, address) {
    const url = address.trim();
    let parsed;
    try { parsed = new URL(url); } catch { throw new Error('Enter a complete https://, http://, or mailto: link.'); }
    if (!['https:', 'http:', 'mailto:'].includes(parsed.protocol) || /[\s<>\u0000-\u001f]/u.test(url)) {
        throw new Error('Use an https://, http://, or mailto: link without spaces.');
    }
    const text = (label.trim() || url).replace(/[\\[\]]/g, '\\$&').replace(/[\r\n]+/g, ' ');
    return `[${text}](<${url}>)`;
}

export function automationAction(message) {
    if (message.kind === 'note') return 'added a private note';
    if (['sent', 'delivered'].includes(message.delivery)) return 'sent a message';
    if (message.delivery === 'queued') return 'queued a message';
    if (message.delivery === 'sending') return 'is sending a message';
    if (message.delivery === 'failed') return 'created a message · delivery failed';
    if (message.delivery === 'suppressed') return 'created a message · delivery suppressed';
    if (message.delivery === 'translation_pending' && message.rule_name) return 'is translating a message in the background';
    if (['held', 'translation_pending'].includes(message.delivery)) return 'created a message awaiting review';
    return 'created a message';
}
