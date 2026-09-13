export const languages = [
    ['en', 'English'], ['ar', 'Arabic'], ['es', 'Spanish'], ['fr', 'French'], ['de', 'German'],
    ['pt', 'Portuguese'], ['it', 'Italian'], ['nl', 'Dutch'], ['tr', 'Turkish'], ['ru', 'Russian'],
    ['uk', 'Ukrainian'], ['pl', 'Polish'], ['ro', 'Romanian'], ['el', 'Greek'], ['cs', 'Czech'],
    ['sv', 'Swedish'], ['da', 'Danish'], ['fi', 'Finnish'], ['no', 'Norwegian'], ['hu', 'Hungarian'],
    ['he', 'Hebrew'], ['fa', 'Persian'], ['ur', 'Urdu'], ['hi', 'Hindi'], ['bn', 'Bengali'],
    ['ta', 'Tamil'], ['te', 'Telugu'], ['id', 'Indonesian'], ['ms', 'Malay'], ['vi', 'Vietnamese'],
    ['th', 'Thai'], ['fil', 'Filipino'], ['ja', 'Japanese'], ['ko', 'Korean'],
    ['zh-CN', 'Chinese (Simplified)'], ['zh-TW', 'Chinese (Traditional)'], ['sw', 'Swahili'],
];
export function languageName(code) {
    if (!code) return 'Not detected yet';
    const known = languages.find(([value]) => value === code);
    if (known) return known[1];
    try { return new Intl.DisplayNames(['en'], { type: 'language' }).of(code); } catch { return code; }
}
export function normalizeLanguage(value) {
    while (Array.isArray(value)) value = value[0];
    if (typeof value !== 'string' || !/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/.test(value)) return null;
    return ({ iw: 'he', tl: 'fil' })[value] || value;
}
export function decodeEntities(value) {
    // Decode entity tokens individually so provider-supplied markup is never parsed as a document.
    return value.replace(/&(?:#x[0-9a-f]+|#\d+|[a-z][a-z0-9]+);/gi, entity => {
        const numeric = /^&#(x?)([0-9a-f]+);$/i.exec(entity);
        if (numeric) {
            const point = parseInt(numeric[2], numeric[1] ? 16 : 10);
            return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
        }
        return ({ '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&apos;': "'", '&nbsp;': ' ' })[entity] || entity;
    });
}
const protectedPattern = /\x60{3}[\s\S]*?\x60{3}|\x60[^\x60\r\n]*\x60|!\[[^\]]*\]\([^)\r\n]+\)|https?:\/\/[^\s<>"')]+|\/api\/v1\/inline-images\/[a-f0-9-]{36}|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\r?\n[ \t]*/gi;
const htmlPattern = /<(?:[^"'<>]|"[^"]*"|'[^']*')*>/g;
const imagePattern = /!\[([^\]]*)\]\(([^)\r\n]+)\)/g;
const imageAttributes = /\b(alt|title)(\s*=\s*)(["'])(.*?)\3/gi;
const escapeHtml = text => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
function imageTagSegments(tag) {
    const result = [];
    let cursor = 0;
    for (const match of tag.matchAll(imageAttributes)) {
        const start = match.index + match[1].length + match[2].length + 1;
        result.push({ text: tag.slice(cursor, start), literal: true });
        result.push(...segments(decodeEntities(match[4])).map(part => ({ ...part, escapeHtml: true })));
        cursor = start + match[4].length;
    }
    result.push({ text: tag.slice(cursor), literal: true });
    return result;
}
function segments(text, format = 'text') {
    if (format === 'html') {
        const result = [];
        let cursor = 0, protectedDepth = 0;
        for (const match of text.matchAll(htmlPattern)) {
            const content = text.slice(cursor, match.index);
            if (protectedDepth) result.push({ text: content, literal: true });
            else result.push(...segments(decodeEntities(content)).map(part => ({ ...part, escapeHtml: true })));
            const tag = match[0];
            result.push(...(!protectedDepth && /^<img\b/i.test(tag) ? imageTagSegments(tag) : [{ text: tag, literal: true }]));
            if (/^<(code|pre|script|style)\b/i.test(tag)) protectedDepth++;
            if (/^<\/(code|pre|script|style)\b/i.test(tag)) protectedDepth = Math.max(0, protectedDepth - 1);
            cursor = match.index + match[0].length;
        }
        result.push(...segments(decodeEntities(text.slice(cursor))).map(part => ({ ...part, escapeHtml: true })));
        return result;
    }
    const result = [];
    let cursor = 0;
    for (const match of text.matchAll(protectedPattern)) {
        if (match.index > cursor) result.push({ text: text.slice(cursor, match.index), literal: false });
        const image = /^!\[([^\]]*)\]\((.*)\)$/.exec(match[0]);
        if (image) {
            result.push({ text: '![', literal: true });
            result.push(...segments(image[1]).map(part => ({ ...part, imageLabel: true })));
            const title = /^(.*?)(\s+["'])(.*)(["'])$/.exec(image[2]);
            result.push({ text: '](' + (title ? title[1] + title[2] : image[2]), literal: true });
            if (title) {
                result.push(...segments(title[3]).map(part => ({ ...part, imageLabel: true })));
                result.push({ text: title[4], literal: true });
            }
            result.push({ text: ')', literal: true });
        } else result.push({ text: match[0], literal: true });
        cursor = match.index + match[0].length;
    }
    if (cursor < text.length) result.push({ text: text.slice(cursor), literal: false });
    return result;
}
export function assertProtectedContent(original, translated) {
    const tokens = text => [...text.matchAll(protectedPattern)].map(match => match[0].replace(imagePattern, (_, label, destination) => '![](' + destination.replace(/\s+["'].*["']$/, '') + ')')).filter(token => !/^\r?\n/.test(token)).sort();
    if (JSON.stringify(tokens(original)) !== JSON.stringify(tokens(translated))) throw new Error('Translation changed a link, image, email address, or code block. Nothing was sent; try again.');
}
export function validateReplyPreview(preview) {
    if (!preview?.body?.trim() || !preview.subject?.trim()) throw new Error('The translated reply and subject cannot be empty.');
    if ([...preview.body].length > 50000 || [...preview.subject].length > 500 || /[\r\n]/.test(preview.subject)) throw new Error('The translated reply or subject is too long or contains invalid line breaks.');
    assertProtectedContent(preview.originalBody, preview.body);
}
function chunks(text, limit = 3500) {
    const points = [...text], result = [];
    for (let at = 0; at < points.length;) {
        let end = Math.min(points.length, at + limit);
        if (end < points.length) {
            const lower = Math.max(at + 1, end - 600);
            while (end > lower && !/\s/.test(points[end - 1])) end--;
            if (end === lower) {
                end = Math.min(points.length, at + limit);
            }
        }
        result.push(points.slice(at, end).join(''));
        at = end;
    }
    return result;
}
export function createTranslator({ fetchImpl = (...args) => fetch(...args), timeoutMs = 20000 } = {}) {
    async function request(url, options, signal) {
        const timeout = AbortSignal.timeout(timeoutMs);
        const response = await fetchImpl(url, { ...options, credentials: 'omit', referrerPolicy: 'strict-origin-when-cross-origin', signal: signal ? AbortSignal.any([timeout, signal]) : timeout });
        if (!response.ok) {
            const error = new Error(response.status === 429 ? 'Google translation quota reached. Try again later.' : 'Google rejected translation (' + response.status + '). Check the key and its website restrictions in Settings → Translation.');
            error.status = response.status;
            throw error;
        }
        return response.json();
    }
    async function translateBatch(texts, source, target, key, signal, provider) {
        if (provider === 'areviews') {
            const result = await request('https://translate-pa.googleapis.com/v1/translateHtml', {
                method: 'POST', headers: { 'Content-Type': 'application/json+protobuf', 'X-Goog-Api-Key': key },
                body: JSON.stringify([[texts.map(escapeHtml), source, target], 'te']),
            }, signal);
            if (!Array.isArray(result?.[0]) || result[0].length !== texts.length || result[0].some(text => typeof text !== 'string' || !text.trim())) throw new Error('Google returned an empty or incomplete translation.');
            return result[0].map((text, index) => ({ text: decodeEntities(text), sourceLanguage: normalizeLanguage(result[1]?.[index]) || normalizeLanguage(result[1]) || (source !== 'auto' ? source : null) }));
        }
        const result = await request('https://translation.googleapis.com/language/translate/v2', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Goog-Api-Key': key },
            body: JSON.stringify({ q: texts, target, format: 'text', ...(source === 'auto' ? {} : { source }) }),
        }, signal);
        const translated = result?.data?.translations;
        if (!Array.isArray(translated) || translated.length !== texts.length || translated.some(item => typeof item?.translatedText !== 'string' || !item.translatedText.trim())) throw new Error('Google returned an empty or incomplete translation.');
        return translated.map(item => ({ text: decodeEntities(item.translatedText), sourceLanguage: normalizeLanguage(item.detectedSourceLanguage) || (source !== 'auto' ? source : null) }));
    }
    return async function translate(text, { source = 'auto', target = 'en', key, signal, format = 'text' } = {}) {
        if (!text?.trim()) throw new Error('Enter some text to translate.');
        if (!normalizeLanguage(target) || (source !== 'auto' && !normalizeLanguage(source))) throw new Error('Select a valid language.');
        if (!key) throw new Error('Add a Google browser translation key in Settings → Translation.');
        const parts = segments(text, format).flatMap(segment => segment.literal ? [segment] : chunks(segment.text).map(text => ({ ...segment, text })));
        const detected = new Map();
        const encode = (text, segment) => segment.escapeHtml ? escapeHtml(text) : segment.imageLabel ? escapeHtml(text).replace(/\[/g, '&#91;').replace(/\]/g, '&#93;').replace(/\\/g, '&#92;') : text;
        const batches = [];
        let batch = [], length = 0;
        for (const part of parts.filter(part => !part.literal && part.text.trim())) {
            const size = [...escapeHtml(part.text)].length;
            if (batch.length && (batch.length === 128 || length + size > 5000)) { batches.push(batch); batch = []; length = 0; }
            batch.push(part); length += size;
        }
        if (batch.length) batches.push(batch);
        let provider = 'areviews';
        for (const batch of batches) {
            signal?.throwIfAborted();
            let result;
            try { result = await translateBatch(batch.map(part => part.text), source, target, key, signal, provider); }
            catch (error) {
                signal?.throwIfAborted();
                if (provider === 'cloud' || error.status === 429 || error.name === 'TimeoutError') throw error;
                provider = 'cloud';
                result = await translateBatch(batch.map(part => part.text), source, target, key, signal, provider);
            }
            batch.forEach((part, index) => {
                const translated = result[index];
                part.translated = part.text.match(/^\s*/)[0] + translated.text.trim() + part.text.match(/\s*$/)[0];
                if (translated.sourceLanguage) detected.set(translated.sourceLanguage, (detected.get(translated.sourceLanguage) || 0) + part.text.length);
            });
        }
        signal?.throwIfAborted();
        const output = parts.map(part => encode(part.translated ?? part.text, part)).join('');
        if (format === 'html') {
            const structure = html => [...html.matchAll(htmlPattern)].map(match => /^<img\b/i.test(match[0]) ? match[0].replace(imageAttributes, '$1$2$3$3') : match[0]);
            if (JSON.stringify(structure(text)) !== JSON.stringify(structure(output))) throw new Error('Translation changed the email formatting. Try again.');
        }
        assertProtectedContent(text, output);
        return { text: output, sourceLanguage: [...detected].sort((a, b) => b[1] - a[1])[0]?.[0] || null, targetLanguage: target };
    };
}
export const translateText = createTranslator();
export function previewMatches(preview, body, subject, context) {
    return Boolean(preview && preview.originalBody === body && preview.originalSubject === subject && JSON.stringify(preview.context) === JSON.stringify(context));
}
export async function prepareReply(body, subject, context, options, translate = translateText) {
    if (!context?.target) throw new Error('Detect or select the customer language before translating your reply.');
    const reply = await translate(body, { ...options, source: 'auto', target: context.target });
    if (!reply.text.trim() || !subject.trim() || [...subject].length > 500 || /[\r\n]/.test(subject)) throw new Error('Google returned an invalid reply or the subject is invalid. Nothing was sent.');
    const preview = { originalBody: body, originalSubject: subject, body: reply.text, subject,
        source: reply.sourceLanguage || options.adminLanguage || 'en', context: JSON.parse(JSON.stringify(context)) };
    validateReplyPreview(preview);
    return preview;
}
export function replyPayload(preview) {
    return { original_body: preview.originalBody, subject: preview.subject, source_language: preview.source, context: preview.context };
}

