<script setup>
import { ref, nextTick, watch } from 'vue';
import { api } from '../store';
import { cannedImageIds, removeCannedImage, removedCannedImageIds } from '../replyEditor';
import { replyLink } from '../replyFormatting';
import ReplyEditor from './ReplyEditor.vue';
import ReplyFormattingToolbar from './ReplyFormattingToolbar.vue';

const props = defineProps({ modelValue: { type: String, default: '' }, disabled: Boolean });
const emit = defineEmits(['update:modelValue', 'uploading']);
const editor = ref(), imageInput = ref(), uploading = ref(false), error = ref('');
const deletingImage = ref(false), imageNotice = ref('');
const cleanupFailed = ref(false), pendingImageRemovals = new Set();
const linkOpen = ref(false), linkLabel = ref(''), linkAddress = ref('');
let linkSelection = { start: 0, end: 0 };

watch([uploading, deletingImage], () => emit('uploading', uploading.value || deletingImage.value), { flush: 'sync' });
watch(() => props.modelValue, (current, previous) => {
    const removed = removedCannedImageIds(previous, current);
    removed.forEach(id => pendingImageRemovals.add(id));
    if (removed.length) cleanupImages();
});

function insert(text) { editor.value?.insert(text); }
function format(marker) {
    const start = editor.value.selectionStart, end = editor.value.selectionEnd;
    insert(marker + (props.modelValue.slice(start, end) || 'text') + marker);
}
function openLink() {
    linkSelection = { start: editor.value.selectionStart, end: editor.value.selectionEnd };
    linkLabel.value = props.modelValue.slice(linkSelection.start, linkSelection.end);
    linkAddress.value = ''; error.value = ''; linkOpen.value = true;
}
function insertLink() {
    try {
        const markdown = replyLink(linkLabel.value, linkAddress.value);
        editor.value.setSelectionRange(linkSelection.start, linkSelection.end);
        insert(markdown); linkOpen.value = false; error.value = '';
    } catch (exception) { error.value = exception.message; }
}
async function uploadImage(file) {
    if (!file || uploading.value || props.disabled) return;
    error.value = '';
    if (!['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        error.value = 'Choose a PNG, JPEG, GIF, or WebP image up to 5 MB.';
        imageInput.value.value = ''; return;
    }
    uploading.value = true;
    try {
        const data = new FormData(); data.append('image', file);
        const result = await api('canned-images', { method: 'POST', body: data });
        uploading.value = false; await nextTick();
        insert('\n' + result.markdown + '\n');
    } catch (exception) { error.value = exception.message; }
    finally { uploading.value = false; if (imageInput.value) imageInput.value.value = ''; }
}

function deleteImage(id) {
    if (props.disabled || uploading.value || deletingImage.value) return;
    emit('update:modelValue', removeCannedImage(props.modelValue, id));
}

async function cleanupImages() {
    if (deletingImage.value) return;
    deletingImage.value = true; cleanupFailed.value = false; error.value = ''; imageNotice.value = '';
    try {
        for (const id of pendingImageRemovals) {
            if (!cannedImageIds(props.modelValue).includes(id)) {
                const result = await api('canned-images/' + id, { method: 'DELETE' });
                imageNotice.value = result.deleted ? 'Unused image deleted.' : 'Image removed. Save your changes.';
            }
            pendingImageRemovals.delete(id);
        }
    } catch (exception) { cleanupFailed.value = true; error.value = 'Image cleanup failed. ' + exception.message; }
    finally { deletingImage.value = false; }
}
</script>

<template>
    <div class="canned-response-editor">
        <span class="canned-message-label">Message</span>
        <div class="canned-composer">
            <ReplyEditor ref="editor" :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" :disabled="disabled || uploading || deletingImage" placeholder="Enter canned response text…" aria-label="Canned response message" expanded removable-images @image="uploadImage" @delete-image="deleteImage" />
            <ReplyFormattingToolbar :disabled="disabled || uploading || deletingImage" :uploading-image="uploading" @format="format" @insert="insert" @link="openLink" @image="imageInput.click()" />
        </div>
        <input ref="imageInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden @change="uploadImage($event.target.files[0])" />
        <p v-if="uploading" class="form-description" role="status">Uploading image…</p>
        <p v-else-if="deletingImage" class="form-description" role="status">Deleting image…</p>
        <p v-else class="form-description">Paste, drop, or upload an image to display it in your response. PNG, JPEG, GIF or WebP, up to 5 MB each.</p>
        <p v-if="error" class="error-message" role="alert">{{ error }}</p>
        <button v-if="cleanupFailed" type="button" class="text-button" :disabled="disabled || uploading || deletingImage" @click="cleanupImages">Retry image cleanup</button>
        <p v-if="imageNotice" class="form-description" role="status">{{ imageNotice }}</p>
        <div v-if="linkOpen" class="form-stack canned-link-fields" @keydown.enter.prevent="insertLink">
            <label>Link text<input v-model="linkLabel" /></label>
            <label>Link address<input v-model="linkAddress" placeholder="https://example.com" /></label>
            <div class="form-actions"><button type="button" class="secondary-button" @click="linkOpen = false">Cancel link</button><button type="button" class="primary-button" @click="insertLink">Insert link</button></div>
        </div>
        <div class="variable-options"><span>Insert a variable</span><button v-for="variable in ['name', 'email', 'ticket_id', 'subject', 'agent']" :key="variable" type="button" :disabled="disabled || uploading || deletingImage" @click="insert('{{' + variable + '}}')">{{ ['{', '{', variable, '}', '}'].join('') }}</button></div>
    </div>
</template>

<style>
.canned-response-editor{display:flex;flex-direction:column;gap:10px;min-width:0}
.canned-message-label{font-size:12px;font-weight:500}
.canned-composer{border:1px solid var(--line);border-radius:9px;overflow:hidden}
.canned-composer:focus-within{border-color:var(--accent)}
.canned-composer .reply-editor{padding:15px;overflow:auto}
.canned-composer .format-toolbar{display:flex;flex-wrap:wrap}
.canned-link-fields{padding:14px;border:1px solid var(--line);border-radius:8px}
</style>
