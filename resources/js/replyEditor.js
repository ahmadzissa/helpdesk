import { appUrl } from './urls.js';

const imagePattern = /!\[([^\]\r\n]*)\]\((<[^<>\r\n]+>|[^\s()]+)\)/gi;

export function imageLink(address, label = 'Image') {
    const url = address.trim();
    let parsed;
    try { parsed = new URL(url); } catch { throw new Error('Enter a complete https:// or http:// image URL.'); }
    if (!['https:', 'http:'].includes(parsed.protocol) || parsed.username || parsed.password || /[\s<>"\\\u0000-\u001f]/u.test(url)) {
        throw new Error('Use an https:// or http:// image URL without spaces or credentials.');
    }
    const name = label.trim().replace(/[\[\]\\\r\n]/g, ' ') || 'Image';
    return `![${name}](<${url}>)`;
}

function imageSource(destination) {
    const url = destination.replace(/^<|>$/g, '');
    if (/^\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36}$/i.test(url)) return appUrl(url);
    try { imageLink(url); return url; } catch { return null; }
}

export function normalizeImagePaste(text) {
    if (/^https?:\/\/\S+(?:\.(?:png|jpe?g|gif|webp|avif)|\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36})(?:[?#]\S*)?$/i.test(text.trim())) {
        try { return imageLink(text); } catch { return text; }
    }
    const normalized = text.replace(/\\(!\[[^\]\r\n]*\]\(\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36}\))/gi, '$1');
    return /!\[[^\]\r\n]*\]\(\/api\/v1\/(?:inline-images|canned-images)\/[a-f0-9-]{36}\)/i.test(normalized)
        ? normalized.replace(/&#(?:x20|32);/gi, ' ')
        : text;
}

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

function formattedHtml(tag, prefix, content, suffix, attributes = '') {
    return `<${tag} data-md-prefix="${escapeHtml(prefix)}" data-md-suffix="${escapeHtml(suffix)}"${attributes}>${content}</${tag}>`;
}

function inlineHtml(text, depth = 0) {
    if (depth > 20) return escapeHtml(text).replace(/\n/g, '<br>');
    let html = '';
    for (let offset = 0; offset < text.length;) {
        const rest = text.slice(offset);
        const escaped = /^\\([!"#$%&'()*+,\-./:;<=>?@[\]\\^_`{|}~])/.exec(rest);
        if (escaped) {
            html += formattedHtml('span', '\\', escapeHtml(escaped[1]), '');
            offset += escaped[0].length;
            continue;
        }
        const code = /^(`+)([^`]+)\1(?!`)/.exec(rest);
        if (code) {
            html += formattedHtml('code', code[1], escapeHtml(code[2]).replace(/\n/g, '<br>'), code[1]);
            offset += code[0].length;
            continue;
        }
        const link = text[offset - 1] !== '!' && /^\[((?:\\.|[^\]\\\n])+)\]\((<[^<>\n]+>|[^\s()]+)\)/.exec(rest);
        if (link) {
            const url = link[2].replace(/^<|>$/g, '');
            if (/^(https?:\/\/|mailto:)/i.test(url) && !/[\s<>\u0000-\u001f]/u.test(url)) {
                html += formattedHtml('a', '[', inlineHtml(link[1], depth + 1), '](' + link[2] + ')', ` href="${escapeHtml(url)}"`);
                offset += link[0].length;
                continue;
            }
        }
        const marker = /^(\*\*|__|~~|\*|_)/.exec(rest)?.[0];
        if (marker && !(marker.includes('_') && /[\p{L}\p{N}]/u.test(text[offset - 1] || '')) && !/^\s/.test(rest.slice(marker.length))) {
            let end = text.indexOf(marker, offset + marker.length);
            while (end !== -1 && (text[end - 1] === '\\' || /\s/.test(text[end - 1]) || (marker.includes('_') && /[\p{L}\p{N}]/u.test(text[end + marker.length] || '')))) {
                end = text.indexOf(marker, end + marker.length);
            }
            if (end > offset + marker.length) {
                const tag = marker === '~~' ? 's' : marker.length === 2 ? 'strong' : 'em';
                html += formattedHtml(tag, marker, inlineHtml(text.slice(offset + marker.length, end), depth + 1), marker);
                offset = end + marker.length;
                continue;
            }
        }
        if (marker) {
            html += escapeHtml(marker);
            offset += marker.length;
            continue;
        }
        html += text[offset] === '\n' ? '<br>' : escapeHtml(text[offset]);
        offset++;
    }
    return html;
}

export function removeCannedImage(text, id) {
    return text.replace(imagePattern, (markdown, label, path) => path.toLowerCase() === '/api/v1/canned-images/' + id.toLowerCase() ? '' : markdown);
}

export function cannedImageIds(text) {
    return [...new Set([...text.matchAll(imagePattern)]
        .filter(match => match[2].toLowerCase().startsWith('/api/v1/canned-images/'))
        .map(match => match[2].split('/').at(-1).toLowerCase()))];
}

export function removedCannedImageIds(previous, current) {
    const remaining = new Set(cannedImageIds(current));
    return cannedImageIds(previous).filter(id => !remaining.has(id));
}

export function replyEditorHtml(text, { removableImages = false } = {}) {
    let html = '', offset = 0;
    for (const match of text.matchAll(imagePattern)) {
        html += inlineHtml(text.slice(offset, match.index));
        const source = imageSource(match[2]);
        if (!source) {
            html += inlineHtml(match[0]);
            offset = match.index + match[0].length;
            continue;
        }
        const img = `<img src="${escapeHtml(source)}" alt="${escapeHtml(match[1])}" data-markdown="${escapeHtml(match[0])}" contenteditable="false" draggable="false" referrerpolicy="no-referrer">`;
        html += removableImages && match[2].startsWith('/api/v1/canned-images/')
            ? `<span class="editor-image" data-editor-image="true" data-markdown="${escapeHtml(match[0])}" contenteditable="false"><button type="button" class="editor-image-delete" data-delete-image="${match[2].split('/').at(-1)}" aria-label="Remove image" title="Remove image">×</button>${img}</span>`
            : img;
        offset = match.index + match[0].length;
    }
    return html + inlineHtml(text.slice(offset));
}

export function replyEditorText(node) {
    if (node.nodeType === 3) return node.textContent;
    if (node.nodeType === 1) {
        if (node.hasAttribute('data-editor-placeholder')) return '';
        if (node.tagName === 'IMG' || node.hasAttribute('data-editor-image')) return node.getAttribute('data-markdown') || '';
        if (node.tagName === 'BR') return '\n';
    }
    const content = Array.from(node.childNodes, replyEditorText).join('');
    return content ? markdownPrefix(node) + content + (node.getAttribute?.('data-md-suffix') || '') : '';
}

function markdownPrefix(node) {
    return node.getAttribute?.('data-md-prefix') || '';
}

function isAtomic(node) {
    return node.nodeType === 1 && (['IMG', 'BR'].includes(node.tagName) || node.hasAttribute('data-editor-image'));
}

// Editor selections use Markdown offsets so toolbar actions and # shortcuts share the saved text's coordinates.
export function replyEditorOffset(root, target, offset) {
    function visit(node, start) {
        if (node === target) {
            return start + (node.nodeType === 3 ? offset : markdownPrefix(node).length + Array.from(node.childNodes).slice(0, offset).reduce((length, child) => length + replyEditorText(child).length, 0));
        }
        if (isAtomic(node)) return null;
        let position = start + markdownPrefix(node).length;
        for (const child of node.childNodes || []) {
            const found = visit(child, position);
            if (found !== null) return found;
            position += replyEditorText(child).length;
        }
        return null;
    }
    return visit(root, 0) ?? 0;
}

export function replyEditorPoint(root, offset) {
    function visit(node, remaining) {
        if (node.nodeType === 3) return [node, Math.min(remaining, node.textContent.length)];
        remaining = Math.max(0, remaining - markdownPrefix(node).length);
        for (const [index, child] of Array.from(node.childNodes).entries()) {
            const length = replyEditorText(child).length;
            if (remaining === 0) return [node, index];
            if (remaining < length) {
                return isAtomic(child) ? [node, index + 1] : visit(child, remaining);
            }
            remaining -= length;
        }
        return [node, node.childNodes.length];
    }
    return visit(root, Math.max(0, offset));
}
