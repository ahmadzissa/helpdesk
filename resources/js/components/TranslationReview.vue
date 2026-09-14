<script setup>
import { ref, onMounted } from 'vue';
import { api, state } from '../store';
import { prepareReply, replyPayload, languageName, validateReplyPreview } from '../translation';
import Modal from './Modal.vue';
const props = defineProps({ message: Object, ticket: Object, detectLanguage: Function });
const emit = defineEmits(['close', 'saved']);
const preview = ref(null), busy = ref(false), error = ref(''), showOriginal = ref(false);
async function translate() {
    busy.value = true; error.value = ''; preview.value = null;
    let automaticSend = false;
    try {
        const config = await api('translation/config');
        state.workspace.translation = config.settings;
        await props.detectLanguage?.();
        const latest = await api('tickets/' + props.ticket.id);
        const live = latest.ticket.messages.find(m => m.id === props.message.id);
        if (!live || live.attempt_id || !['translation_pending', 'held', 'saved'].includes(live.delivery)) throw new Error('This reply changed. Close this preview and refresh the ticket.');
        preview.value = await prepareReply(live.original_body ?? live.body, latest.ticket.subject, latest.ticket.translation_context, { key: config.key, adminLanguage: config.settings.target });
        automaticSend = preview.value.sameLanguage || (config.settings.outgoing && config.settings.auto_send);
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
    if (automaticSend && preview.value) await send();
}
async function send(sendOriginal = false) {
    sendOriginal = sendOriginal === true;
    if (sendOriginal && state.workspace.translation?.outgoing) return;
    if ((!preview.value && !sendOriginal) || busy.value) return;
    busy.value = true; error.value = '';
    try {
        if (!sendOriginal) validateReplyPreview(preview.value);
        const payload = sendOriginal ? { body: props.message.original_body ?? props.message.body, send_original: true } : { body: preview.value.body, translation: replyPayload(preview.value) };
        await api('messages/' + props.message.id + '/prepare-translation', { method: 'POST', body: payload });
        emit('saved');
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
onMounted(translate);
</script>
<template>
<Modal :title="state.workspace.translation?.outgoing && state.workspace.translation?.auto_send ? 'Translate and send reply' : 'Review translated reply'" wide @close="!busy && emit('close')"><div class="form-stack"><p class="muted">This updates the existing reply and keeps its attachments and original text.</p><p v-if="error" class="error-message" role="alert">{{ error }}</p><p v-if="busy && !preview" class="muted" role="status">Translating in your browser…</p>
<template v-if="preview"><strong>Reply in {{ languageName(preview.context.target) }}</strong><label>{{ preview.sameLanguage ? "Original reply · language already matches" : "Translated reply" }}<textarea v-model="preview.body" rows="9" :disabled="busy" :readonly="preview.sameLanguage" /></label><button type="button" class="text-button" @click="showOriginal = !showOriginal">{{ showOriginal ? 'Hide original' : 'Show original' }}</button><pre v-if="showOriginal" class="translation-plain" dir="auto">{{ preview.originalBody }}</pre></template>
<div class="form-actions"><button v-if="error && !state.workspace.translation?.outgoing" class="secondary-button" @click="send(true)" :disabled="busy">Send original language</button><button class="secondary-button" @click="translate" :disabled="busy">{{ preview ? 'Translate again' : 'Retry translation' }}</button><button v-if="preview" class="primary-button" @click="send" :disabled="busy || !preview.body.trim() || !preview.subject.trim()">{{ busy ? 'Saving…' : state.workspace.sending_safety?.paused ? 'Save translation for review' : preview.sameLanguage ? 'Send original reply' : 'Send translated reply' }}</button></div></div></Modal>
</template>
