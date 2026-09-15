import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeImagePaste, replyEditorHtml, replyEditorText, removeCannedImage, removedCannedImageIds } from '../../resources/js/replyEditor.js';
import { setAppBasePath } from '../../resources/js/urls.js';

const image = '![Image](/api/v1/inline-images/3d4e9de1-0187-4887-9c77-1b004f3d98a4)';

test('keyboard deletion and replacing selected content detect every removed canned image', () => {
    const cannedImage = image.replace('inline-images', 'canned-images');
    const id = '3d4e9de1-0187-4887-9c77-1b004f3d98a4';
    const otherId = '00000000-0000-0000-0000-000000000000';
    const other = cannedImage.replace(id, otherId);
    assert.deepEqual(removedCannedImageIds('Before\n' + cannedImage + '\nAfter', 'Before\n\nAfter'), [id]);
    assert.deepEqual(removedCannedImageIds(cannedImage + other + image, 'Replacement text'), [id, otherId]);
    assert.deepEqual(removedCannedImageIds(cannedImage + other, ''), [id, otherId]);
    assert.deepEqual(removedCannedImageIds(image, ''), []);
});

test('retained duplicates, image moves, caption changes and undo do not schedule image deletion', () => {
    const cannedImage = image.replace('inline-images', 'canned-images');
    assert.deepEqual(removedCannedImageIds(cannedImage + '\n' + cannedImage, cannedImage), []);
    assert.deepEqual(removedCannedImageIds('Before ' + cannedImage, cannedImage + ' After'), []);
    assert.deepEqual(removedCannedImageIds(cannedImage, cannedImage.replace('Image', 'Updated caption')), []);
    assert.deepEqual(removedCannedImageIds('', cannedImage), []);
});

test('canned editor shows a delete control above each image without including the control in saved text', () => {
    const cannedImage = image.replace('inline-images', 'canned-images');
    const html = replyEditorHtml(cannedImage, { removableImages: true });
    assert.ok(html.includes('aria-label="Remove image"'));
    assert.ok(html.indexOf('<button') < html.indexOf('<img'));
    assert.equal(replyEditorHtml(cannedImage).includes('<button'), false);
    assert.equal(replyEditorHtml(image, { removableImages: true }).includes('<button'), false);
    const wrapper = { nodeType: 1, tagName: 'SPAN', hasAttribute: name => name === 'data-editor-image', getAttribute: name => name === 'data-markdown' ? cannedImage : null, childNodes: [{ nodeType: 3, textContent: '×' }] };
    assert.equal(replyEditorText(wrapper), cannedImage);
});

test('deleting a canned image removes all its occurrences while retaining surrounding text and other images', () => {
    const cannedImage = image.replace('inline-images', 'canned-images');
    const id = '3d4e9de1-0187-4887-9c77-1b004f3d98a4';
    const other = cannedImage.replace(id, '00000000-0000-0000-0000-000000000000');
    assert.equal(removeCannedImage('Before\n' + cannedImage + '\nAfter ' + cannedImage.replace('Image', 'Another label') + other + image, id), 'Before\n\nAfter ' + other + image);
});

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
