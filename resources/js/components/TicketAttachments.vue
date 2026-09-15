<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';

defineProps({ files: { type: Array, default: () => [] } });
const preview = ref(null), loading = ref(false), failed = ref(false);
function openPreview(file) {
    preview.value = file;
    loading.value = true;
    failed.value = false;
}
</script>

<template>
    <div v-if="files.length" class="message-files">
        <div v-for="(file, index) in files" :key="index" class="ticket-attachment">
            <button v-if="file.preview_url" type="button" class="attachment-link attachment-preview-button" :aria-label="'View image: ' + file.name" @click="openPreview(file)">
                <Icon name="image" /><span>{{ file.name }}<small>{{ Math.ceil(file.size / 1024) }} KB · View image</small></span><Icon name="expand" :size="14" />
            </button>
            <a v-else :href="file.url" class="attachment-link" :download="file.name" title="Download attachment"><Icon name="file" /><span>{{ file.name }}<small>{{ Math.ceil(file.size / 1024) }} KB</small></span><Icon name="download" :size="14" /></a>
            <a v-if="file.preview_url" :href="file.url" :download="file.name" class="attachment-download icon-button" :aria-label="'Download ' + file.name" title="Download image"><Icon name="download" :size="16" /></a>
        </div>
    </div>
    <Modal v-if="preview" :title="preview.name" wide panel-class="attachment-preview-modal" @close="preview = null">
        <p v-if="loading" class="muted" role="status">Loading image…</p>
        <p v-if="failed" class="error-message" role="alert">Unable to load this image. You can try opening it in a new tab or download it below.</p>
        <img v-show="!loading && !failed" :src="preview.preview_url" :alt="preview.name" class="attachment-preview-image" @load="loading = false" @error="loading = false; failed = true" />
        <div class="form-actions">
            <a class="text-button" :href="preview.preview_url" target="_blank" rel="noopener noreferrer">Open original image</a>
            <a class="secondary-button" :href="preview.url" :download="preview.name"><Icon name="download" :size="16" />Download</a>
        </div>
    </Modal>
</template>

<style>
.ticket-attachment{display:flex;align-items:stretch;max-width:100%;min-width:0}
.ticket-attachment .attachment-link{min-width:0;text-align:start}.ticket-attachment .attachment-link>span{min-width:0;overflow-wrap:anywhere}.ticket-attachment svg{flex-shrink:0}
.attachment-preview-button{cursor:zoom-in;border-start-end-radius:0;border-end-end-radius:0}.attachment-preview-button:hover{background:var(--soft)}
.ticket-attachment .attachment-download{align-self:stretch;width:38px;height:auto;flex-shrink:0;border:1px solid var(--line);border-inline-start:0;border-radius:0 7px 7px 0}
.attachment-preview-modal>header h2{overflow-wrap:anywhere;min-width:0}.attachment-preview-modal>header button{flex-shrink:0}
.attachment-preview-image{display:block;max-width:100%;max-height:70vh;object-fit:contain;margin:auto}.attachment-preview-modal .form-actions{flex-wrap:wrap}
</style>
