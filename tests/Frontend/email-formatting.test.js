import test from 'node:test';
import assert from 'node:assert/strict';
import { createTranslator } from '../../resources/js/translation.js';
import { messageHtml, setAppBasePath } from '../../resources/js/urls.js';
import { renderEmailImages, imagePreviewSource } from '../../resources/js/emailImages.js';

test('displayed images support keyboard enlargement and keep remote and inline URLs intact', () => {
    setAppBasePath('/helpdesk/public');
    const html = '<p>Photo</p><img data-email-src="https://example.com/photo.png?a=1&amp;b=2" alt="A > B"><img src="/api/v1/attachments/276/0/inline" />';
    const visible = renderEmailImages(html, true);
    assert.ok(visible.includes('src="https://example.com/photo.png?a=1&amp;b=2"'));
    assert.ok(visible.includes('src="/helpdesk/public/api/v1/attachments/276/0/inline"'));
    assert.ok(visible.includes('alt="A > B"'));
    assert.equal((visible.match(/tabindex="0" role="button"/g) || []).length, 2);
    assert.ok(!visible.includes('data-email-src'));
    setAppBasePath('');
});

test('disabling image display blocks both external and embedded image loads until Show images is used', () => {
    const html = '<img data-email-src="https://example.com/photo.png"><img src="/api/v1/attachments/276/0/inline">';
    const hidden = renderEmailImages(html, false);
    assert.ok(!/\ssrc=/.test(hidden));
    assert.equal((hidden.match(/data-email-src=/g) || []).length, 2);
    const shown = renderEmailImages(html, true);
    assert.equal((shown.match(/\ssrc=/g) || []).length, 2);
});

test('image previews resolve local paths and never open executable or unsupported URL schemes', () => {
    const base = 'http://localhost/helpdesk/public/tickets/276';
    assert.equal(imagePreviewSource('/helpdesk/public/api/v1/attachments/276/0/inline', base), 'http://localhost/helpdesk/public/api/v1/attachments/276/0/inline');
    assert.equal(imagePreviewSource('https://example.com/photo.png', base), 'https://example.com/photo.png');
    for (const source of ['javascript:alert(1)', 'data:image/svg+xml,anything', 'file:///secret', '']) assert.equal(imagePreviewSource(source, base), null);
});

test('HTML translation preserves layout, links, image references and encodes provider markup', async () => {
    const calls = [];
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        const input = JSON.parse(options.body)[0][0];
        calls.push(...input);
        return { ok: true, json: async () => [input.map(text => text.replace('Hola', 'Hello').replace('equipo', '<team>')), ['es']] };
    } });
    const original = '<div dir="rtl"><p>Hola &amp; <b>equipo</b></p><a href="https://example.com/?a=1&amp;b=2">https://example.com/?a=1&amp;b=2</a><img data-email-src="https://example.com/picture.png" alt="Picture"><img src="/api/v1/attachments/7/0/inline"><table><tr><td>Hola</td></tr></table></div>';
    const result = await translate(original, { key: 'synthetic-key', format: 'html' });
    assert.equal(result.text, original.replaceAll('Hola', 'Hello').replace('equipo', '&lt;team&gt;'));
    assert.equal(result.sourceLanguage, 'es');
    assert.ok(calls.every(input => !input.includes('<') && !input.includes('https://') && !input.includes('attachments')));
});

test('HTML image titles and alt text join the message batch while code and attributes stay untouched', async () => {
    let calls = 0;
    const translate = createTranslator({ fetchImpl: async (url, options) => {
        calls++;
        assert.deepEqual(JSON.parse(options.body)[0][0], ['Hola', 'captura', 'A &gt; B']);
        return { ok: true, json: async () => [['Hello', 'screenshot', 'Translated > title'], ['es']] };
    } });
    const html = '<p>Hola</p><img src="https://example.com/image.png" alt="captura" title="A > B"><pre><code>const hello = "Hola";</code></pre>';
    const result = await translate(html, { key: 'synthetic-key', format: 'html' });
    assert.equal(calls, 1);
    assert.equal(result.text, html.replace('<p>Hola', '<p>Hello').replace('alt="captura"', 'alt="screenshot"').replace('title="A > B"', 'title="Translated &gt; title"'));
});

test('incoming attachment images keep working under the XAMPP subdirectory', () => {
    setAppBasePath('/helpdesk/public');
    const html = '<img src="/api/v1/attachments/276/0/inline"><img data-email-src="https://example.com/image.png">';
    const rendered = messageHtml(html);
    assert.equal(rendered, html.replace('src="/api', 'src="/helpdesk/public/api'));
    assert.equal(messageHtml(rendered), rendered);
    setAppBasePath('');
});
