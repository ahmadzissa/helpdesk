<script setup>
import { computed, ref, watch } from 'vue';
import { state } from '../store';
import { renderEmailImages, imagePreviewSource } from '../emailImages';
import Modal from './Modal.vue';

const props = defineProps({ html: { type: String, default: '' } });
const manuallyShown = ref(false), preview = ref(null);
const automaticImages = computed(() => state.workspace.settings.general?.show_email_images !== false);
const showImages = computed(() => automaticImages.value || manuallyShown.value);
const hasImages = computed(() => /<img\b/i.test(props.html));
const content = computed(() => renderEmailImages(props.html, showImages.value));
watch(automaticImages, () => { manuallyShown.value = false; preview.value = null; });
function openImage(event) {
    if (event.target.tagName !== 'IMG' || !showImages.value) return;
    const source = imagePreviewSource(event.target.getAttribute('src'), document.baseURI);
    if (!source) return;
    event.preventDefault();
    preview.value = { source, name: event.target.getAttribute('alt') || 'Email image' };
}
function imageKeydown(event) { if (event.key === 'Enter' || event.key === ' ') openImage(event); }
</script>

<template>
    <div v-if="hasImages && !showImages" class="email-image-notice"><span>Images are hidden.</span><button type="button" class="text-button" @click="manuallyShown = true">Show images</button></div>
    <div class="message-body email-content" dir="auto" v-html="content" @click="openImage" @keydown="imageKeydown" />
    <Modal v-if="preview" :title="preview.name" wide @close="preview = null"><img class="email-image-preview" :src="preview.source" :alt="preview.name" referrerpolicy="no-referrer" /><div class="form-actions"><a class="text-button" :href="preview.source" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Open original image</a></div></Modal>
</template>

<style>
.email-image-notice{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 20px 0;padding:9px 12px;border:1px solid var(--line);border-radius:6px;font-size:11px;color:var(--muted);background:var(--soft)}
.email-content{overflow-wrap:anywhere;overflow-x:auto;line-height:1.7;min-width:0}
.email-content img{max-width:100%;height:auto;vertical-align:middle;border-radius:4px;cursor:zoom-in}
.email-content img:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.email-image-preview{display:block;max-width:100%;max-height:75vh;object-fit:contain;margin:auto}
.email-content img[data-email-src]{display:none}
.email-content table{border-collapse:collapse;max-width:100%;margin:12px 0}
.email-content td,.email-content th{padding:6px 10px;vertical-align:top}
.email-content th{text-align:start;border-bottom:1px solid var(--line)}
.email-content h1,.email-content h2,.email-content h3{margin:16px 0 10px;line-height:1.3}
.email-content div:empty{min-height:.7em}
.email-content ul{list-style:disc}.email-content ol{list-style:decimal}
</style>
