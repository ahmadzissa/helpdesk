import { ref, reactive, computed, watch, onBeforeUnmount } from 'vue';
import { api, state } from './store.js';
import { translateText, prepareReply, previewMatches } from './translation.js';

export function useTicketTranslation(ticket, body, privateNote) {
    const settings = computed(() => state.workspace.translation || { incoming: true, outgoing: false, target: 'en' });
    const original = reactive({}), errors = reactive({}), pending = reactive({});
    const translationError = ref(''), translating = ref(false), preview = ref(null);
    const detecting = ref(false), languageError = ref('');
    let configPromise, detectionPromise, disposed = false, incomingController = new AbortController(), replyController, replySnapshot = false, incomingRun = 0;
    const messageJobs = new Map();
    const previewReady = computed(() => !privateNote.value && previewMatches(preview.value, body.value, ticket.value?.subject, ticket.value?.translation_context));
    const autoReply = computed(() => settings.value.outgoing && !privateNote.value);
    const automaticSend = computed(() => autoReply.value && settings.value.auto_send);
    async function config(fresh = false) {
        if (fresh || !configPromise) configPromise = api('translation/config').catch(e => { configPromise = null; throw e; });
        const result = await configPromise;
        state.workspace.translation = result.settings;
        return result;
    }
    function setCustomer(result, preserveContext = false) {
        if (disposed || !ticket.value) return;
        ticket.value.customer_language = result.customer_language;
        ticket.value.translation_context = preserveContext ? { ...ticket.value.translation_context, target: result.customer_language?.language || null } : result.translation_context;
    }
    function translateMessage(message, force = false) {
        if (messageJobs.has(message.id)) return messageJobs.get(message.id);
        const work = (async () => {
        const target = settings.value.target;
        if (!force && message.translation?.target_language === target && message.translation.source_hash === message.source_hash) return;
        pending[message.id] = true; delete errors[message.id];
        try {
            const currentId = ticket.value.id, { key } = await config();
            const format = message.translation_format || 'text';
            const result = await translateText(message.translation_text || message.body, { target, key, format, signal: incomingController.signal });
            const saved = await api('messages/' + message.id + '/translation', { method: 'PUT', body: { body: result.text, body_format: format, source_language: result.sourceLanguage, target_language: target, source_hash: message.source_hash } });
            if (disposed || ticket.value.id !== currentId || settings.value.target !== target) return;
            message.translation = saved.translation;
            const liveMessage = ticket.value.messages.find(m => m.id === message.id);
            if (liveMessage && liveMessage.source_hash === message.source_hash) liveMessage.translation = saved.translation;
            setCustomer(saved, true);
        } catch (e) { if (e.name !== 'AbortError' && !disposed) errors[message.id] = e.message; throw e; }
        finally { pending[message.id] = false; }
        })();
        messageJobs.set(message.id, work);
        return work.finally(() => messageJobs.delete(message.id));
    }
    function detectLanguage(force = false) {
        if (detectionPromise) return detectionPromise;
        if (!force && ticket.value.customer_language?.language && (ticket.value.customer_language.manual || ticket.value.customer_language.source_message_id === ticket.value.language_sample?.id)) return Promise.resolve();
        detecting.value = true; languageError.value = '';
        detectionPromise = (async () => {
        try {
            if (force) setCustomer(await api('tickets/' + ticket.value.id + '/language', { method: 'PUT', body: { language: null } }));
            const sample = ticket.value.language_sample;
            if (!sample?.body?.trim()) throw new Error('No customer email is available to detect. Select the language manually.');
            await translateMessage(sample, true);
            if (!ticket.value.customer_language?.language) throw new Error('Google could not detect a language. Select the customer language manually.');
        } catch (e) { languageError.value = e.message; throw e; } finally { detecting.value = false; }
        })().finally(() => { detectionPromise = null; });
        return detectionPromise;
    }
    async function setLanguage(language) {
        languageError.value = '';
        try {
            setCustomer(await api('tickets/' + ticket.value.id + '/language', { method: 'PUT', body: { language } }));
            if (!language) await detectLanguage();
        } catch (e) { languageError.value = e.message; }
    }
    async function translateAll(force = false) {
        const run = ++incomingRun;
        delete errors.all;
        if (!ticket.value || (!force && !settings.value.incoming)) return;
        try {
            await config();
            if (ticket.value.language_sample) await detectLanguage();
            const messages = [...ticket.value.messages].filter(m => m.kind !== 'note').reverse();
            for (const message of messages) {
                if (disposed || run !== incomingRun || (!force && !settings.value.incoming)) return;
                await translateMessage(message);
            }
        } catch (e) { if (!disposed && e.name !== 'AbortError') errors.all = e.message; }
    }
    async function prepareToSend(required = autoReply.value) {
        if (privateNote.value || !required || previewReady.value) return true;
        return await prepare() && Boolean(automaticSend.value || preview.value?.sameLanguage);
    }
    async function prepare() {
        if (translating.value || !body.value.trim()) return false;
        translating.value = true; translationError.value = ''; preview.value = null;
        replySnapshot = false;
        const controller = new AbortController(); replyController = controller;
        try {
            const { key, settings: currentSettings } = await config(true);
            const currentId = ticket.value.id;
            const latest = await api('tickets/' + currentId);
            controller.signal.throwIfAborted();
            if (ticket.value.id !== currentId) return false;
            ticket.value = latest.ticket;
            await detectLanguage();
            controller.signal.throwIfAborted();
            const input = body.value, subject = ticket.value.subject, context = JSON.parse(JSON.stringify(ticket.value.translation_context));
            replySnapshot = true;
            const result = await prepareReply(input, subject, context, { key, adminLanguage: currentSettings.target, signal: controller.signal });
            controller.signal.throwIfAborted();
            if (!previewMatches(result, body.value, ticket.value.subject, ticket.value.translation_context)) throw new Error('The reply or customer language changed. Translate again.');
            preview.value = result;
            return true;
        } catch (e) {
            if (e.name !== 'AbortError') translationError.value = e.message;
            return false;
        } finally { if (replyController === controller) translating.value = false; }
    }
    watch([body, privateNote], () => {
        preview.value = null;
        replyController?.abort();
        translationError.value = '';
    }, { flush: 'sync' });
    watch([() => ticket.value?.subject, () => JSON.stringify(ticket.value?.translation_context)], () => {
        preview.value = null;
        if (replySnapshot) replyController?.abort();
    }, { flush: 'sync' });
    watch(() => ticket.value ? ticket.value.id + ':' + ticket.value.messages.map(m => m.id + '/' + m.source_hash).join(',') + ':' + settings.value.target + ':' + settings.value.incoming : '', () => {
        if (ticket.value) translateAll();
    });
    onBeforeUnmount(() => { disposed = true; incomingRun++; incomingController.abort(); replyController?.abort(); });
    return { settings, original, errors, pending, translateMessage, translateAll,
        preview, previewReady, translating, translationError, prepare, prepareToSend, autoReply, automaticSend, detecting, languageError, detectLanguage, setLanguage };
}
