import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeImagePaste, replyEditorHtml, replyEditorText, replyEditorOffset, replyEditorPoint, removeCannedImage, removedCannedImageIds } from '../../resources/js/replyEditor.js';
import { setAppBasePath } from '../../resources/js/urls.js';

const image = '![Image](/api/v1/inline-images/3d4e9de1-0187-4887-9c77-1b004f3d98a4)';

function textNode(textContent) { return { nodeType: 3, textContent }; }
function elementNode(tagName, attributes = {}, ...childNodes) {
    return { nodeType: 1, tagName, childNodes, hasAttribute: name => name in attributes, getAttribute: name => attributes[name] ?? null };
}

test('saved canned responses display escaped punctuation and toolbar formatting as readable text', () => {
    const html = replyEditorHtml('\\#Go to Shopify \\>\\> Settings\n**Inform me once you accept it!** _Thanks_ ~~old~~ `**literal**`');
    assert.ok(html.includes('data-md-prefix="\\" data-md-suffix="">#</span>'));
    assert.ok(html.includes('data-md-prefix="\\" data-md-suffix="">&gt;</span>'));
    assert.ok(html.includes('<strong data-md-prefix="**" data-md-suffix="**">Inform me once you accept it!</strong>'));
    assert.ok(html.includes('<em data-md-prefix="_" data-md-suffix="_">Thanks</em>'));
    assert.ok(html.includes('<s data-md-prefix="~~" data-md-suffix="~~">old</s>'));
    assert.ok(html.includes('<code data-md-prefix="`" data-md-suffix="`">**literal**</code>'));
    assert.equal(replyEditorHtml('order_status and unfinished **text'), 'order_status and unfinished **text');
    assert.equal(replyEditorHtml('**To **'), '**To **');
});

test('formatted links retain destinations and never turn unsafe protocols or HTML into executable markup', () => {
    assert.ok(replyEditorHtml('[**Help**](<https://example.com/?a=1&b=2>)').includes('href="https://example.com/?a=1&amp;b=2"><strong'));
    for (const source of ['[click](javascript:alert)', '[click](data:text/html,test)', '[click](<https://example.com/\" onclick=\"alert>)']) {
        assert.equal(replyEditorHtml(source).includes('<a '), false);
    }
    assert.ok(replyEditorHtml('**<script>alert(1)</script>**').includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
});

test('editing formatted content preserves Markdown while selecting visible text uses the correct source offsets', () => {
    const boldText = textNode('accept'), escapedText = textNode('#'), linkText = textNode('Help');
    const bold = elementNode('STRONG', { 'data-md-prefix': '**', 'data-md-suffix': '**' }, boldText);
    const escaped = elementNode('SPAN', { 'data-md-prefix': '\\', 'data-md-suffix': '' }, escapedText);
    const link = elementNode('A', { 'data-md-prefix': '[', 'data-md-suffix': '](<https://example.com>)' }, linkText);
    const root = elementNode('DIV', {}, textNode('Please '), bold, textNode(' '), escaped, textNode(' '), link);
    const source = 'Please **accept** \\# [Help](<https://example.com>)';
    assert.equal(replyEditorText(root), source);
    assert.equal(replyEditorOffset(root, boldText, 2), source.indexOf('accept') + 2);
    assert.equal(replyEditorOffset(root, escapedText, 1), source.indexOf('#') + 1);
    assert.equal(replyEditorOffset(root, linkText, 3), source.indexOf('Help') + 3);
    for (const node of [boldText, escapedText, linkText]) {
        for (let index = 0; index <= node.textContent.length; index++) {
            const offset = replyEditorOffset(root, node, index);
            assert.equal(replyEditorOffset(root, ...replyEditorPoint(root, offset)), offset);
        }
    }
    assert.deepEqual(replyEditorPoint(root, source.length), [root, root.childNodes.length]);
    boldText.textContent = 'approved';
    assert.equal(replyEditorText(root), source.replace('accept', 'approved'));
    boldText.textContent = '';
    assert.equal(replyEditorText(bold), '');
});

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
