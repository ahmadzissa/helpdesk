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
const htmlPattern = /<[^>]*>/g;
function segments(text, format = 'text') {
    if (format === 'html') {
        const result = [];
        let cursor = 0;
        for (const match of text.matchAll(htmlPattern)) {
            result.push(...segments(decodeEntities(text.slice(cursor, match.index))).map(part => ({ ...part, escapeHtml: true })));
            result.push({ text: match[0], literal: true });
            cursor = match.index + match[0].length;
        }
        result.push(...segments(decodeEntities(text.slice(cursor))).map(part => ({ ...part, escapeHtml: true })));
        return result;
    }
    const result = [];
    let cursor = 0;
    for (const match of text.matchAll(protectedPattern)) {
        if (match.index > cursor) result.push({ text: text.slice(cursor, match.index), literal: false });
        result.push({ text: match[0], literal: true });
        cursor = match.index + match[0].length;
    }
    if (cursor < text.length) result.push({ text: text.slice(cursor), literal: false });
    return result;
}
export function assertProtectedContent(original, translated) {
    const tokens = text => [...text.matchAll(protectedPattern)].map(match => match[0]).filter(token => !/^\r?\n/.test(token)).sort();
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
    async function translateChunk(text, source, target, key, signal, provider) {
        if (provider === 'areviews') {
            const result = await request('https://translate-pa.googleapis.com/v1/translateHtml', {
                method: 'POST', headers: { 'Content-Type': 'application/json+protobuf', 'X-Goog-Api-Key': key },
                body: JSON.stringify([[text, source, target], 'te']),
            }, signal);
            if (typeof result?.[0]?.[0] !== 'string' || !result[0][0].trim()) throw new Error('Google returned an empty or invalid translation.');
            return { text: decodeEntities(result[0][0]), sourceLanguage: normalizeLanguage(result[1]) || (source !== 'auto' ? source : null) };
        }
        const result = await request('https://translation.googleapis.com/language/translate/v2', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Goog-Api-Key': key },
            body: JSON.stringify({ q: text, target, format: 'text', ...(source === 'auto' ? {} : { source }) }),
        }, signal);
        const translated = result?.data?.translations?.[0];
        if (typeof translated?.translatedText !== 'string' || !translated.translatedText.trim()) throw new Error('Google returned an empty or invalid translation.');
        return { text: decodeEntities(translated.translatedText), sourceLanguage: normalizeLanguage(translated.detectedSourceLanguage) || (source !== 'auto' ? source : null) };
    }
    return async function translate(text, { source = 'auto', target = 'en', key, signal, format = 'text' } = {}) {
        if (!text?.trim()) throw new Error('Enter some text to translate.');
        if (!normalizeLanguage(target) || (source !== 'auto' && !normalizeLanguage(source))) throw new Error('Select a valid language.');
        if (!key) throw new Error('Add a Google browser translation key in Settings → Translation.');
        const parts = segments(text, format).flatMap(segment => segment.literal ? [segment] : chunks(segment.text).map(text => ({ ...segment, text })));
        const results = [], detected = new Map();
        const encode = (text, segment) => segment.escapeHtml ? text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : text;
        let provider = 'areviews';
        for (const segment of parts) {
            if (segment.literal || !segment.text.trim()) { results.push(encode(segment.text, segment)); continue; }
            const part = segment.text;
            signal?.throwIfAborted();
            const leading = part.match(/^\s*/)[0], trailing = part.match(/\s*$/)[0];
            let result;
            try { result = await translateChunk(part, source, target, key, signal, provider); }
            catch (error) {
                signal?.throwIfAborted();
                if (provider === 'cloud' || error.status === 429 || error.name === 'TimeoutError') throw error;
                provider = 'cloud';
                result = await translateChunk(part, source, target, key, signal, provider);
            }
            results.push(encode(leading + result.text.trim() + trailing, segment));
            if (result.sourceLanguage) detected.set(result.sourceLanguage, (detected.get(result.sourceLanguage) || 0) + part.length);
        }
        signal?.throwIfAborted();
        const output = results.join('');
        if (format === 'html') {
            if (JSON.stringify([...text.matchAll(htmlPattern)].map(match => match[0])) !== JSON.stringify([...output.matchAll(htmlPattern)].map(match => match[0]))) throw new Error('Translation changed the email formatting. Try again.');
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
    const title = await translate(subject, { ...options, source: 'auto', target: context.target });
    if (!reply.text.trim() || !title.text.trim() || [...title.text].length > 500 || /[\r\n]/.test(title.text)) throw new Error('Google returned an invalid reply or subject. Nothing was sent.');
    return { originalBody: body, originalSubject: subject, body: reply.text, subject: title.text,
        source: reply.sourceLanguage || options.adminLanguage || 'en', context: JSON.parse(JSON.stringify(context)) };
}
export function replyPayload(preview) {
    return { original_body: preview.originalBody, subject: preview.subject, source_language: preview.source, context: preview.context };
}

