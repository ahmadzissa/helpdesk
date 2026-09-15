<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { normalizeImagePaste, replyEditorHtml, replyEditorText } from '../replyEditor';

const props = defineProps({ modelValue: { type: String, default: '' }, disabled: Boolean, placeholder: String, expanded: Boolean });
const emit = defineEmits(['update:modelValue', 'image']);
const element = ref();
let selection = { start: 0, end: 0 };

function rememberSelection() {
    const selected = window.getSelection();
    if (!selected?.rangeCount || !element.value?.contains(selected.anchorNode) || !element.value.contains(selected.focusNode)) return;
    const range = selected.getRangeAt(0), prefix = range.cloneRange();
    prefix.selectNodeContents(element.value);
    prefix.setEnd(range.startContainer, range.startOffset);
    const start = replyEditorText(prefix.cloneContents()).length;
    selection = { start, end: start + replyEditorText(range.cloneContents()).length };
}

function setSelectionRange(start, end = start) {
    const range = document.createRange();
    function point(offset) {
        const walker = document.createTreeWalker(element.value, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (node.nodeType !== 3 && !['IMG', 'BR'].includes(node.tagName)) continue;
            const length = replyEditorText(node).length;
            if (offset <= length) {
                if (node.nodeType === 3) return [node, offset];
                const index = Array.prototype.indexOf.call(node.parentNode.childNodes, node);
                return [node.parentNode, index + (offset > 0 ? 1 : 0)];
            }
            offset -= length;
        }
        return [element.value, element.value.childNodes.length];
    }
    range.setStart(...point(start)); range.setEnd(...point(end));
    const selected = window.getSelection();
    selected.removeAllRanges(); selected.addRange(range);
    selection = { start, end };
}

function ensurePlaceholder() {
    if (!element.value.lastChild?.hasAttribute?.('data-editor-placeholder')) {
        const placeholder = document.createElement('br');
        placeholder.setAttribute('data-editor-placeholder', 'true');
        element.value.appendChild(placeholder);
    }
}

function sync() {
    ensurePlaceholder();
    rememberSelection();
    emit('update:modelValue', replyEditorText(element.value));
}

function render() {
    if (!element.value || replyEditorText(element.value) === props.modelValue) return;
    element.value.innerHTML = replyEditorHtml(props.modelValue) + '<br data-editor-placeholder="true">';
}

function insert(text) {
    if (props.disabled) return;
    element.value.focus(); setSelectionRange(selection.start, selection.end);
    // Native insertion keeps typing, pasted images, and deletion in the browser's undo history.
    const caret = selection.start + text.length;
    document.execCommand('insertHTML', false, replyEditorHtml(text));
    ensurePlaceholder();
    setSelectionRange(caret);
    sync();
}

function paste(event) {
    event.preventDefault();
    if (props.disabled) return;
    const image = [...(event.clipboardData?.items || [])].find(item => item.type.startsWith('image/'));
    if (image) { rememberSelection(); emit('image', image.getAsFile()); return; }
    insert(normalizeImagePaste(event.clipboardData?.getData('text/plain') || ''));
}

function drop(event) {
    event.preventDefault();
    if (props.disabled) return;
    const image = [...(event.dataTransfer?.files || [])].find(file => file.type.startsWith('image/'));
    if (image) emit('image', image);
    else insert(normalizeImagePaste(event.dataTransfer?.getData('text/plain') || ''));
}

function keydown(event) {
    if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && !event.isComposing) {
        event.preventDefault(); insert('\n');
    }
}

watch(() => props.modelValue, render, { flush: 'post' });
onMounted(() => {
    element.value.innerHTML = replyEditorHtml(props.modelValue) + '<br data-editor-placeholder="true">';
    document.addEventListener('selectionchange', rememberSelection);
});
onBeforeUnmount(() => document.removeEventListener('selectionchange', rememberSelection));
defineExpose({
    get selectionStart() { rememberSelection(); return selection.start; },
    get selectionEnd() { rememberSelection(); return selection.end; },
    focus: () => element.value?.focus(), setSelectionRange, insert,
});
</script>

<template>
    <div ref="element" class="reply-editor visual-reply-editor" :class="{ expanded }" :contenteditable="!disabled" :aria-disabled="disabled" :data-placeholder="placeholder" :data-empty="!modelValue" role="textbox" aria-multiline="true" aria-label="Write a reply" dir="auto" @input="sync" @keydown="keydown" @paste="paste" @dragover.prevent @drop="drop" />
</template>

<style>
.reply-editor.visual-reply-editor{white-space:pre-wrap;overflow-wrap:anywhere;cursor:text;width:100%;height:auto;min-height:110px;max-height:240px;outline:none}
.reply-editor.visual-reply-editor.expanded{height:auto;min-height:240px;max-height:400px}
.visual-reply-editor[data-empty="true"]:before{content:attr(data-placeholder);color:var(--muted);pointer-events:none;position:absolute}
.visual-reply-editor img{display:inline-block;max-width:100%;max-height:190px;width:auto;height:auto;object-fit:contain;vertical-align:middle;border:1px solid var(--line);border-radius:6px;margin:5px 0}
.visual-reply-editor[aria-disabled="true"]{opacity:.65;cursor:default}
</style>
