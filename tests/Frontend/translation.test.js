import test from 'node:test';
import assert from 'node:assert/strict';
import { createTranslator, createLanguageDetector, normalizeLanguage, prepareReply, previewMatches, replyPayload, validateReplyPreview, messageNeedsTranslation } from '../../resources/js/translation.js';

const key = 'synthetic-test-browser-key';
const ok = value => ({ ok: true, json: async () => value });
const primary = (text, language = 'es') => ok([Array.isArray(text) ? text : [text], [language]]);
const requestText = options => JSON.parse(options.body)[0][0];

test('matching message and agent languages hide translation controls, including existing same-language translations', () => {
    assert.equal(messageNeedsTranslation({ kind: 'inbound' }, 'en', 'en'), false);
    assert.equal(messageNeedsTranslation({ kind: 'outbound', original_body: 'Hello' }, 'en', 'en'), false);
    assert.equal(messageNeedsTranslation({ kind: 'inbound', source_hash: 'current', translation: { source_hash: 'current', source_language: 'en', target_language: 'en' } }, 'en', 'en'), false);
    assert.equal(messageNeedsTranslation({ kind: 'inbound' }, 'ar', 'ar'), false);
    assert.equal(messageNeedsTranslation({ kind: 'note' }, 'es', 'en'), false);
});

test('different or unknown message languages retain translation controls and stale detection is ignored', () => {
    assert.equal(messageNeedsTranslation({ kind: 'inbound' }, 'es', 'en'), true);
    assert.equal(messageNeedsTranslation({ kind: 'inbound' }, null, 'en'), true);
    assert.equal(messageNeedsTranslation({ kind: 'inbound', source_hash: 'current', translation: { source_hash: 'current', source_language: 'es' } }, 'en', 'en'), true);
    assert.equal(messageNeedsTranslation({ kind: 'inbound', source_hash: 'current', translation: { source_hash: 'old', source_language: 'es' } }, 'en', 'en'), false);
    assert.equal(messageNeedsTranslation({ kind: 'outbound', translation_context: { target: 'es' } }, 'en', 'en'), true);
});

for (const [language, body] of [['en', 'Hello, we can help.\n\nThanks!'], ['ar', 'مرحباً، يمكننا مساعدتك.\n\nشكراً لك!']]) {
    test(`a reply already in ${language} keeps its exact original without requesting translation`, async () => {
        const detect = createLanguageDetector({ fetchImpl: async (url, options) => {
            assert.equal(url, 'https://translation.googleapis.com/language/translate/v2/detect');
            assert.equal(options.credentials, 'omit');
            assert.ok(JSON.parse(options.body).q[0].includes(body.split('\n')[0]));
            return ok({ data: { detections: [[{ language }]] } });
        } });
        const preview = await prepareReply(body, 'Subject', { target: language }, { key }, () => assert.fail('No translation request should be made'), detect);
        assert.equal(preview.sameLanguage, true);
        assert.equal(preview.body, body);
        assert.equal(replyPayload(preview).source_language, language);
        preview.body += ' Edited';
        validateReplyPreview(preview);
        assert.equal(preview.body, body);
    });
}

test('failed language checks cannot silently send an original reply', async () => {
    const detect = createLanguageDetector({ fetchImpl: async () => ({ ok: false, status: 429 }) });
    await assert.rejects(prepareReply('Hello', 'Subject', { target: 'en' }, { key }, () => assert.fail('Quota errors must stop'), detect), /quota/);
});

test('keys without a detection endpoint use existing translation detection and preserve matching originals', async () => {
    const detect = createLanguageDetector({ fetchImpl: async () => ({ ok: false, status: 403 }) });
    const preview = await prepareReply('Hello!', 'Subject', { target: 'en' }, { key }, async () => ({ text: 'Hello.', sourceLanguage: 'en' }), detect);
    assert.equal(preview.body, 'Hello!');
    assert.equal(preview.sameLanguage, true);
});

test('Areviews response preserves originals, source language, paragraphs, links, email addresses, images and code', async () => {
    const source = 'Hola\n\nVisita https://example.com/orders y customer@example.com\n![captura](/api/v1/inline-images/01234567-89ab-cdef-0123-456789abcdef)\n' + String.fromCharCode(96) + 'order_id' + String.fromCharCode(96);
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        assert.equal(url, 'https://translate-pa.googleapis.com/v1/translateHtml');
        assert.equal(options.credentials, 'omit');
        assert.equal(options.headers['X-Goog-Api-Key'], key);
        assert.deepEqual(JSON.parse(options.body)[0].slice(1), ['auto', 'en']);
        return primary(requestText(options).map(text => text.replace('Hola', 'Hello').replace('Visita', 'Visit').replace(' y ', ' and ')));
    } });
    const result = await translate(source, { key });
    assert.equal(result.sourceLanguage, 'es');
    assert.equal(result.text, source.replace('Hola', 'Hello').replace('Visita', 'Visit').replace(' y ', ' and '));
    assert.ok(source.startsWith('Hola'));
});

test('Cloud fallback omits auto source and reads detectedSourceLanguage', async () => {
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        calls++;
        if (calls === 1) return { ok: false, status: 403 };
        assert.equal(url, 'https://translation.googleapis.com/language/translate/v2');
        assert.equal(JSON.parse(options.body).source, undefined);
        return ok({ data: { translations: [{ translatedText: 'Hello &amp; welcome', detectedSourceLanguage: 'fr' }] } });
    } });
    assert.deepEqual(await translate('Bonjour et bienvenue', { key }), { text: 'Hello & welcome', sourceLanguage: 'fr', targetLanguage: 'en' });
    assert.equal(calls, 2);
});

test('empty, malformed and failed results never silently return the untranslated reply', async () => {
    for (const response of [ok({}), ok([['']]), { ok: false, status: 403 }, { ok: false, status: 429 }]) {
        const translate = createTranslator({ fetchImpl: async () => response });
        await assert.rejects(translate('Please help', { key }));
    }
});

test('quota errors stop immediately and do not multiply requests through fallback', async () => {
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async () => { calls++; return { ok: false, status: 429 }; } });
    await assert.rejects(translate('Hola', { key }), /quota/);
    assert.equal(calls, 1);
});

test('links and line breaks never enter Google requests and invented links block the result', async () => {
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        const text = requestText(options);
        assert.ok(text.every(part => !part.includes('https://example.com') && !part.includes('\n')));
        return primary('Hola https://malicious.example');
    } });
    await assert.rejects(translate('Hello\nhttps://example.com', { key }), /changed/);
});
test('long multilingual emails are chunked and retain exact boundaries', async () => {
    const source = ('مرحبا بالعالم 🌍 hello world\n'.repeat(280)) + 'End';
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        calls++;
        const text = requestText(options);
        assert.ok(text.every(part => [...part].length <= 3500));
        assert.ok(text.length <= 128);
        return primary(text.map(part => part.trim()), 'ar');
    } });
    const result = await translate(source, { key });
    assert.ok(calls > 1);
    assert.equal(result.text, source);
    assert.equal(result.sourceLanguage, 'ar');
});

test('abort stops translation before any fallback or partial preview', async () => {
    const controller = new AbortController();
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async () => { calls++; controller.abort(); throw new DOMException('Cancelled', 'AbortError'); } });
    await assert.rejects(translate('Hello', { key, signal: controller.signal }), { name: 'AbortError' });
    assert.equal(calls, 1);
});

test('reply preview binds to the exact source body, subject, recipient and language', async () => {
    const context = { recipient: 'synthetic@example.com', cc: [], target: 'es', revision: 1, subject_hash: 'hash' };
    const translate = async (text, options) => { assert.equal(options.target, 'es'); return { text: 'ES: ' + text, sourceLanguage: 'en' }; };
    const preview = await prepareReply('Hello', 'My order', context, { key }, translate, async () => 'en');
    assert.ok(previewMatches(preview, 'Hello', 'My order', context));
    assert.equal(previewMatches(preview, 'Hello edited', 'My order', context), false);
    assert.equal(previewMatches(preview, 'Hello', 'New subject', context), false);
    assert.equal(previewMatches(preview, 'Hello', 'My order', { ...context, target: 'ar' }), false);
    assert.equal(previewMatches(preview, 'Hello', 'My order', { ...context, recipient: 'new@example.com' }), false);
    assert.equal(replyPayload(preview).original_body, 'Hello');
    assert.equal(preview.body, 'ES: Hello');
    const original = await prepareReply('  Hello\n\n', 'Subject', { target: null }, {}, () => assert.fail('No translation needed'), () => assert.fail('No reply detection needed'));
    assert.equal(original.sendOriginal, true);
    assert.equal(original.body, '  Hello\n\n');
    assert.equal(original.subject, 'Subject');
});

test('only the reply body is translated and the subject is unchanged', async () => {
    const calls = [];
    const translate = async text => { calls.push(text); return { text: 'Hola', sourceLanguage: 'en' }; };
    const preview = await prepareReply('Hello', 'Subject', { target: 'es' }, { key }, translate, async () => 'en');
    assert.deepEqual(calls, ['Hello']);
    assert.equal(preview.subject, 'Subject');
});

test('all four support paragraphs share a single Google request', async () => {
    const paragraphs = ['One of the support team will answer your question shortly.', 'Thank you for your patience.', 'Sincerely,', 'Areviews Support Team'];
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        calls++;
        assert.deepEqual(JSON.parse(options.body), [[paragraphs, 'auto', 'te'], 'te']);
        return primary(['మా సహాయ బృందం త్వరలో మీ ప్రశ్నకు సమాధానం ఇస్తుంది.', 'మీ సహనానికి ధన్యవాదాలు.', 'భవదీయులు,', 'Areviews సహాయ బృందం'], 'en');
    } });
    const result = await translate(paragraphs.join('\n\n'), { key, target: 'te' });
    assert.equal(calls, 1);
    assert.equal(result.text.split('\n\n').length, 4);
    assert.equal(result.sourceLanguage, 'en');
});

test('batch fallback keeps order and rejects incomplete translations', async () => {
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        calls++;
        if (calls === 1) return { ok: false, status: 403 };
        assert.deepEqual(JSON.parse(options.body).q, ['Hello', 'Goodbye']);
        return ok({ data: { translations: [{ translatedText: 'Hola', detectedSourceLanguage: 'en' }, { translatedText: 'Adiós', detectedSourceLanguage: 'en' }] } });
    } });
    assert.equal((await translate('Hello\nGoodbye', { key, target: 'es' })).text, 'Hola\nAdiós');
    assert.equal(calls, 2);
    const incomplete = createTranslator({ fetchImpl: async () => ok({ data: { translations: [{ translatedText: 'Hola' }] } }) });
    await assert.rejects(incomplete('Hello\nGoodbye', { key }), /incomplete/);
});

test('image labels and titles are translated with body text while image URLs remain intact', async () => {
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        assert.deepEqual(requestText(options), ['Hola', 'captura', 'pedido']);
        return primary(['Hello', 'screenshot', 'order']);
    } });
    const result = await translate('Hola\n![captura](/api/v1/inline-images/01234567-89ab-cdef-0123-456789abcdef "pedido")', { key });
    assert.equal(result.text, 'Hello\n![screenshot](/api/v1/inline-images/01234567-89ab-cdef-0123-456789abcdef "order")');
});

test('normalizes nested language arrays and rejects invalid detection values', () => {
    assert.equal(normalizeLanguage([['es']]), 'es');
    assert.equal(normalizeLanguage(['iw']), 'he');
    assert.equal(normalizeLanguage('zh-CN'), 'zh-CN');
    assert.equal(normalizeLanguage('auto'), null);
    assert.equal(normalizeLanguage({ language: 'es' }), null);
});

