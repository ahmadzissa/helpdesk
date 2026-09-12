import { messageHtml } from './urls.js';

export function renderEmailImages(html, showImages) {
    return messageHtml(html).replace(/<img\b(?:[^>"']|"[^"]*"|'[^']*')*>/gi, tag => {
        if (!showImages) return tag.replace(/\ssrc="/gi, ' data-email-src="');
        return tag.replace(/\sdata-email-src="/gi, ' src="').replace(/\s*\/?\s*>$/, ' tabindex="0" role="button" title="Enlarge image">');
    });
}

export function imagePreviewSource(source, baseUrl) {
    if (!source) return null;
    try {
        const url = new URL(source, baseUrl);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch { return null; }
}
