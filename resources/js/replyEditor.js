import { appUrl } from './urls.js';

const imagePattern = /!\[([^\]\r\n]*)\]\((\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36})\)/gi;

export function normalizeImagePaste(text) {
    const normalized = text.replace(/\\(!\[[^\]\r\n]*\]\(\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36}\))/gi, '$1');
    return /!\[[^\]\r\n]*\]\(\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36}\)/i.test(normalized)
        ? normalized.replace(/&#(?:x20|32);/gi, ' ')
        : text;
}

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

export function replyEditorHtml(text) {
    let html = '', offset = 0;
    for (const match of text.matchAll(imagePattern)) {
        html += escapeHtml(text.slice(offset, match.index)).replace(/\n/g, '<br>');
        html += `<img src="${escapeHtml(appUrl(match[2]))}" alt="${escapeHtml(match[1])}" data-markdown="${escapeHtml(match[0])}" contenteditable="false" draggable="false">`;
        offset = match.index + match[0].length;
    }
    return html + escapeHtml(text.slice(offset)).replace(/\n/g, '<br>');
}

export function replyEditorText(node) {
    if (node.nodeType === 3) return node.textContent;
    if (node.nodeType === 1) {
        if (node.hasAttribute('data-editor-placeholder')) return '';
        if (node.tagName === 'IMG') return node.getAttribute('data-markdown') || '';
        if (node.tagName === 'BR') return '\n';
    }
    return Array.from(node.childNodes, replyEditorText).join('');
}
