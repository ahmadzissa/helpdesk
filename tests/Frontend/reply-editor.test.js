import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeImagePaste, replyEditorHtml } from '../../resources/js/replyEditor.js';
import { setAppBasePath } from '../../resources/js/urls.js';

const image = '![Image](/api/v1/inline-images/3d4e9de1-0187-4887-9c77-1b004f3d98a4)';

test('pasted image references become images at their position in the reply', () => {
    setAppBasePath('/helpdesk/public');
    const html = replyEditorHtml('Before\n' + image + '\nAfter');
    assert.ok(html.startsWith('Before<br><img src="/helpdesk/public/api/v1/inline-images/'));
    assert.ok(html.includes(`data-markdown="${image}"`));
    assert.ok(html.endsWith('<br>After'));
    assert.equal((replyEditorHtml(image + '\n' + image).match(/<img /g) || []).length, 2);
    setAppBasePath('');
});

test('pasted escaped image syntax and encoded spaces are repaired without interpreting arbitrary HTML', () => {
    assert.equal(normalizeImagePaste('&#x20;\n\\' + image), ' \n' + image);
    assert.equal(normalizeImagePaste('literal &#x20; without an image'), 'literal &#x20; without an image');
    assert.equal(replyEditorHtml('<script>alert(1)</script> & text'), '&lt;script&gt;alert(1)&lt;/script&gt; &amp; text');
    assert.ok(!replyEditorHtml('![Image](javascript:alert(1))').includes('<img'));
    assert.ok(!replyEditorHtml('![Image](https://example.com/photo.png)').includes('<img'));
    const html = replyEditorHtml(image.replace('Image', '" onerror="alert(1)'));
    assert.ok(html.includes('alt="&quot; onerror=&quot;alert(1)"'));
});
