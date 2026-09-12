import test from 'node:test';
import assert from 'node:assert/strict';
import { createTranslator, normalizeLanguage, prepareReply, previewMatches, replyPayload } from '../../resources/js/translation.js';

const key = 'synthetic-test-browser-key';
const ok = value => ({ ok: true, json: async () => value });
const primary = (text, language = 'es') => ok([[text], [language]]);
const requestText = options => JSON.parse(options.body)[0][0];

test('Areviews response preserves originals, source language, paragraphs, links, email addresses, images and code', async () => {
    const source = 'Hola\n\nVisita https://example.com/orders y customer@example.com\n![captura](/api/v1/inline-images/01234567-89ab-cdef-0123-456789abcdef)\n' + String.fromCharCode(96) + 'order_id' + String.fromCharCode(96);
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        assert.equal(url, 'https://translate-pa.googleapis.com/v1/translateHtml');
        assert.equal(options.credentials, 'omit');
        assert.equal(options.headers['X-Goog-Api-Key'], key);
        assert.deepEqual(JSON.parse(options.body)[0].slice(1), ['auto', 'en']);
        return primary(requestText(options).replace('Hola', 'Hello').replace('Visita', 'Visit').replace(' y ', ' and '));
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
        assert.equal(text.includes('https://example.com'), false);
        assert.equal(text.includes('\n'), false);
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
        assert.ok([...text].length <= 3500);
        return primary(text.trim(), 'ar');
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
    const preview = await prepareReply('Hello', 'My order', context, { key }, translate);
    assert.ok(previewMatches(preview, 'Hello', 'My order', context));
    assert.equal(previewMatches(preview, 'Hello edited', 'My order', context), false);
    assert.equal(previewMatches(preview, 'Hello', 'New subject', context), false);
    assert.equal(previewMatches(preview, 'Hello', 'My order', { ...context, target: 'ar' }), false);
    assert.equal(previewMatches(preview, 'Hello', 'My order', { ...context, recipient: 'new@example.com' }), false);
    assert.equal(replyPayload(preview).original_body, 'Hello');
    assert.equal(preview.body, 'ES: Hello');
    await assert.rejects(prepareReply('Hello', 'Subject', { target: null }, { key }, translate), /customer language/);
});

test('subject failure prevents a complete reply preview', async () => {
    const translate = async text => { if (text === 'Subject') throw new Error('Subject failed'); return { text: 'Hola', sourceLanguage: 'en' }; };
    await assert.rejects(prepareReply('Hello', 'Subject', { target: 'es' }, { key }, translate), /Subject failed/);
});

test('normalizes nested language arrays and rejects invalid detection values', () => {
    assert.equal(normalizeLanguage([['es']]), 'es');
    assert.equal(normalizeLanguage(['iw']), 'he');
    assert.equal(normalizeLanguage('zh-CN'), 'zh-CN');
    assert.equal(normalizeLanguage('auto'), null);
    assert.equal(normalizeLanguage({ language: 'es' }), null);
});

