<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { useNavigation } from '../useNavigation';
import { appUrl } from '../urls';
import { createTicketRefresher } from '../ticketRefresh';
import EmailMessageBody from '../components/EmailMessageBody.vue';
import { guardDraftNavigation } from '../draftNavigation';
import { state, api, notify, initials, statusClass, refreshSendingSafety } from '../store';
import Modal from '../components/Modal.vue';
import TicketTools from '../components/TicketTools.vue';
import TicketCustomFields from '../components/TicketCustomFields.vue';
import DeliveryDetails from '../components/DeliveryDetails.vue';
import LanguagePicker from '../components/LanguagePicker.vue';
import TranslationReview from '../components/TranslationReview.vue';
import { useTicketTranslation } from '../useTicketTranslation';
import { languageName, replyPayload, validateReplyPreview } from '../translation';
const route = useNavigation();
const ticket = ref(null), related = ref([]), activity = ref([]), loading = ref(true), error = ref('');
const body = ref(''), privateNote = ref(false), expanded = ref(false), details = ref(window.innerWidth > 1100), sending = ref(false), draftState = ref(''), editor = ref(), scroll = ref(), files = ref([]), fileInput = ref();
const picker = ref(false), recording = ref(false), editing = ref(false), replySearch = ref(''), newTag = ref('');
const editForm = reactive({}), sendStatus = ref('Open'), formatVisible = ref(true);
const sendingAccounts = computed(() => state.workspace.mailboxes.filter(mailbox => mailbox.sending_enabled));
const mailboxUnavailable = computed(() => !sendingAccounts.value.some(mailbox => mailbox.id === ticket.value?.mailbox_id));
const inlineInput = ref(), uploadingImage = ref(false), deliveryMessage = ref(null), imagePreview = ref(false);
const { settings: translationSettings, original, errors: translationErrors, pending: pendingTranslations, subjectOriginal, translateMessage, translateAll, preview, previewReady, translating, translationError, prepare, autoReply, detecting, languageError, detectLanguage, setLanguage } = useTicketTranslation(ticket, body, privateNote);
const translationRequested = ref(false), reviewMessage = ref(null), writtenOriginal = reactive({});
const requiresPreview = computed(() => autoReply.value || (!privateNote.value && translationRequested.value));
async function previewReply() { translationRequested.value = true; await prepare(); }
async function reviewTranslation(message) { try { await detectLanguage(); reviewMessage.value = message; } catch (e) { notify(e.message, true); } }
async function reviewedTranslation() { reviewMessage.value = null; await refreshTicket(); notify('Translated reply saved'); }
const inlinePreviews = computed(() => [...body.value.matchAll(/!\[([^\]]*)\]\((\/api\/v1\/inline-images\/[a-f0-9-]{36})\)/gi)].map(match => ({ name: match[1], url: appUrl(match[2]) })));
const canned = computed(() => state.workspace.replies.filter(r => (r.title + r.shortcut + r.body).toLowerCase().includes(replySearch.value.toLowerCase())));
let draftTimer, deliveryTimer, draftPromise = Promise.resolve(), loaded = false, skipNextDraft = false;
let draftRevision = 0, savedDraftRevision = 0, ticketMutations = 0;
const hasUnsavedDraft = () => loaded && !ticket.value?.merged_into_id && draftRevision !== savedDraftRevision;
const dateTime = date => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: state.workspace.settings.general?.timezone || undefined }).format(new Date(date));
async function load() {
    try {
        const data = await api('tickets/' + route.params.id);
        ticket.value = data.ticket; related.value = data.related; activity.value = data.activity;
        sendStatus.value = data.ticket.status;
        body.value = data.draft?.body || ''; privateNote.value = data.draft?.private || false;
        if (ticket.value.unread && !ticket.value.merged_into_id) { await api('tickets/' + ticket.value.id, { method: 'PATCH', body: { unread: false } }); state.refresh++; }
        setTimeout(() => { loaded = true; scrollBottom(); }, 0);
    } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
function scrollBottom() { if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight; }
function saveDraft() {
    if (!loaded || !ticket.value || ticket.value.merged_into_id) return Promise.resolve();
    clearTimeout(draftTimer);
    const payload = { body: body.value, private: privateNote.value }, id = ticket.value.id, revision = draftRevision;
    draftState.value = 'Saving…';
    draftPromise = draftPromise.catch(() => {}).then(() => api('tickets/' + id + '/draft', { method: 'PUT', body: payload })).then(() => { savedDraftRevision = revision; draftState.value = hasUnsavedDraft() ? 'Unsaved draft' : payload.body ? 'Draft saved' : ''; }).catch(e => { draftState.value = 'Draft not saved'; throw e; });
    return draftPromise;
}
watch([body, privateNote], () => {
    if (skipNextDraft) { skipNextDraft = false; return; }
    if (!loaded || sending.value) return;
    draftRevision++;
    clearTimeout(draftTimer); draftState.value = 'Unsaved draft';
    draftTimer = setTimeout(() => saveDraft().catch(e => notify(e.message, true)), 700);
});
const removeDraftGuard = guardDraftNavigation(router, { isDirty: hasUnsavedDraft, isSending: () => sending.value, save: saveDraft, onError: message => notify(message, true) });
function beforeUnload(event) { if (hasUnsavedDraft() || sending.value) { event.preventDefault(); event.returnValue = ''; } }
onBeforeUnmount(() => {
    ticketRefresher.dispose(); document.removeEventListener('visibilitychange', refreshVisibleTicket);
    removeDraftGuard(); clearTimeout(draftTimer); clearInterval(deliveryTimer); window.removeEventListener('beforeunload', beforeUnload);
    if (hasUnsavedDraft() && !sending.value) saveDraft().catch(e => notify('Couldn’t save your draft. ' + e.message, true));
});
const ticketRefresher = createTicketRefresher({
    getId: () => route.params.id,
    fetchTicket: id => api('tickets/' + id),
    apply: result => {
        const atBottom = !scroll.value || scroll.value.scrollHeight - scroll.value.scrollTop - scroll.value.clientHeight < 100;
        const changed = ticket.value?.messages.at(-1)?.id !== result.ticket.messages.at(-1)?.id;
        ticket.value = result.ticket; related.value = result.related; activity.value = result.activity;
        if (changed && atBottom) nextTick(scrollBottom);
    },
});
function refreshTicket() { return ticketRefresher.refresh(); }
function refreshVisibleTicket() { if (loaded && !sending.value && !ticketMutations && !document.hidden) refreshTicket().catch(() => {}); }
watch(() => state.refresh, refreshVisibleTicket);
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload); document.addEventListener('visibilitychange', refreshVisibleTicket); deliveryTimer = setInterval(refreshVisibleTicket, 15000); });
async function uploadImage(file) {
    if (!file || uploadingImage.value) return;
    if (!['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { notify('Choose a PNG, JPEG, GIF, or WebP image up to 5 MB.', true); return; }
    uploadingImage.value = true;
    try { const data = new FormData(); data.append('image', file); const result = await api('tickets/' + ticket.value.id + '/inline-images', { method: 'POST', body: data }); insert('\n' + result.markdown + '\n'); imagePreview.value = true; notify('Image inserted into your reply'); }
    catch (e) { notify(e.message, true); } finally { uploadingImage.value = false; if (inlineInput.value) inlineInput.value.value = ''; }
}
function pasteImage(event) { const item = [...event.clipboardData.items].find(item => item.type.startsWith('image/')); if (item) { event.preventDefault(); uploadImage(item.getAsFile()); } }
function dropImage(event) { const file = [...(event.dataTransfer?.files || [])].find(file => file.type.startsWith('image/')); if (file) { event.preventDefault(); uploadImage(file); } }
async function update(changes, message = 'Ticket updated') {
    ticketMutations++;
    ticketRefresher.invalidate();
    try { const result = await api('tickets/' + ticket.value.id, { method: 'PATCH', body: changes }); ticketRefresher.invalidate(); Object.assign(ticket.value, result.data); if (changes.status) sendStatus.value = result.data.status; state.refresh++; if (message) notify(message); }
    catch (e) { notify(e.message, true); throw e; }
    finally { ticketMutations--; }
}
async function changeMailbox(event) {
    try { await update({ mailbox_id: Number(event.target.value) }, 'Sending account updated'); }
    catch { event.target.value = ticket.value.mailbox_id ?? ''; }
}
function saveCustomField(key, value) { return update({ custom_fields: { [key]: value } }, ''); }
function refreshTools() { state.refresh++; refreshTicket().catch(e => notify(e.message, true)); }
async function move(folder) { try { await update({ folder }, 'Moved to ' + folder); router.visit(appUrl('/tickets')); } catch {} }
async function retryMessage(message) {
    ticketRefresher.invalidate();
    try { const result = await api('messages/' + message.id + '/retry', { method: 'POST' }); message.delivery = 'queued'; notify(result.message); }
    catch (e) { notify(e.message, true); }
}
async function markUnread() { try { await update({ unread: true }, 'Marked unread'); router.visit(appUrl('/tickets')); } catch {} }
async function send() {
    if (!body.value.trim() || sending.value || translating.value || uploadingImage.value) return;
    if (requiresPreview.value && !previewReady.value) { if (await prepare()) notify('Translation ready. Review the preview, then send.'); return; }
    const sendingOriginal = body.value, sendingPrivate = privateNote.value;
    sending.value = true; ticketRefresher.invalidate(); clearTimeout(draftTimer);
    try {
        await draftPromise.catch(() => {});
        if (body.value !== sendingOriginal || privateNote.value !== sendingPrivate || (requiresPreview.value && !previewReady.value)) throw new Error('The reply changed. Review it before sending.');
        if (requiresPreview.value) validateReplyPreview(preview.value);
        const data = new FormData(); data.append('body', requiresPreview.value ? preview.value.body : body.value); data.append('private', privateNote.value ? '1' : '0'); data.append('status', sendStatus.value);
        if (requiresPreview.value) data.append('translation', JSON.stringify(replyPayload(preview.value)));
        files.value.forEach(file => data.append('attachments[]', file));
        const result = await api('tickets/' + ticket.value.id + '/messages', { method: 'POST', body: data });
        ticket.value = result.data; savedDraftRevision = draftRevision; skipNextDraft = true; body.value = ''; translationRequested.value = false; files.value = []; draftState.value = ''; state.refresh++; await refreshTicket();
        const delivery = ticket.value.messages.at(-1)?.delivery;
        notify(privateNote.value ? 'Private note added' : delivery === 'held' ? 'Reply held. Sending is paused for review.' : delivery === 'queued' ? 'Reply queued for delivery' : 'Reply saved. This mailbox is not connected.');
        refreshSendingSafety().catch(() => {});
        setTimeout(scrollBottom, 0);
    } catch (e) { notify(e.message, true); } finally { sending.value = false; }
}
function insert(text) {
    const start = editor.value?.selectionStart ?? body.value.length, end = editor.value?.selectionEnd ?? start;
    body.value = body.value.slice(0, start) + text + body.value.slice(end);
    setTimeout(() => { editor.value?.focus(); editor.value?.setSelectionRange(start + text.length, start + text.length); }, 0);
}
function format(before, after = before) {
    const start = editor.value?.selectionStart || 0, end = editor.value?.selectionEnd || 0;
    insert(before + (body.value.slice(start, end) || 'text') + after);
}
function insertReply(reply) {
    let text = reply.body;
    const vars = { name: ticket.value.requester_name || ticket.value.requester_email, email: ticket.value.requester_email, ticket_id: ticket.value.id, subject: ticket.value.subject, agent: state.user.name };
    Object.entries(vars).forEach(([key, value]) => { text = text.replaceAll('{{' + key + '}}', value); });
    if (/^#[\w-]+$/.test(body.value.trim())) body.value = '';
    insert(text); picker.value = false;
}
function shortcut(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
    if (e.key === 'Tab') { const reply = state.workspace.replies.find(r => r.shortcut === body.value.trim()); if (reply) { e.preventDefault(); insertReply(reply); } }
}
function addFiles(event) {
    const incoming = Array.from(event.target.files);
    if (files.value.length + incoming.length > 5 || incoming.some(f => f.size > 10 * 1024 * 1024)) { notify('Attach up to 5 files, 10 MB each.', true); }
    else files.value.push(...incoming);
    event.target.value = '';
}
async function addTag() { const tag = newTag.value.trim(); if (tag) { try { await update({ tags: [...new Set([...(ticket.value.tags || []), tag])] }, 'Tag added'); newTag.value = ''; } catch {} } }
function editDetails() {
    Object.assign(editForm, { subject: ticket.value.subject, requester_name: ticket.value.requester_name || '', requester_email: ticket.value.requester_email, company: ticket.value.company || '', cc: (ticket.value.cc || []).join(', ') });
    editing.value = true;
}
async function saveDetails() {
    try { await update({ subject: editForm.subject, requester_name: editForm.requester_name, requester_email: editForm.requester_email, company: editForm.company, cc: editForm.cc.split(',').map(s => s.trim()).filter(Boolean) }); editing.value = false; } catch {}
}
</script>
<template>
<div v-if="loading" class="surface empty-state"><Icon name="loader" class="spin" /><p>Opening conversation…</p></div>
<div v-else-if="error" class="surface empty-state"><Icon name="alert" /><h2>Couldn’t open this ticket</h2><p>{{ error }}</p><Link class="secondary-button" :href="$appUrl('/tickets')">Back to inbox</Link></div>
<main v-else class="ticket-page surface">
    <section class="conversation-column">
        <header class="ticket-subject-row"><Link class="icon-button" :href="$appUrl('/tickets')" aria-label="Back to inbox"><Icon name="back" /></Link><h1 @dblclick="editDetails" :title="ticket.subject" dir="auto">{{ ticket.subject_translation && !subjectOriginal ? ticket.subject_translation.subject : ticket.subject }}</h1><span class="ticket-number">#{{ ticket.id }}</span><button class="icon-button" @click="details = !details" aria-label="Toggle ticket information" :aria-expanded="details"><Icon name="panel" /></button></header>
        <div v-if="ticket.merged_into_id" class="folder-notice merged-notice">Merged into <Link :href="$appUrl('/tickets/' + ticket.merged_into_id)">#{{ ticket.merged_into_id }} · Open main conversation</Link>. This original ticket is read only.</div>
        <div v-if="!ticket.merged_into_id" class="ticket-quickbar"><div>
            <Link :href="$appUrl('/automations')" class="icon-button" title="Automations" aria-label="Automations"><Icon name="bolt" /></Link>
            <button class="icon-button" @click="picker = true" title="Canned responses" aria-label="Canned responses"><Icon name="message" /></button><span class="divider" />
            <button v-if="ticket.folder !== 'inbox'" class="icon-button" @click="move('inbox')" title="Restore to inbox" aria-label="Restore to inbox"><Icon name="inbox" /></button>
            <button class="icon-button" @click="move('archive')" title="Archive" aria-label="Archive ticket"><Icon name="archive" /></button>
            <button class="icon-button" @click="move('spam')" title="Mark spam" aria-label="Mark as spam"><Icon name="spam" /></button>
            <button class="icon-button" @click="move('trash')" title="Move to Trash" aria-label="Move to Trash"><Icon name="trash" /></button><span class="divider" />
            <button class="icon-button" @click="markUnread" title="Mark unread" aria-label="Mark unread"><Icon name="mail" /></button>
            <button class="icon-button" @click="update({ status: 'Closed' }).catch(() => {})" title="Close ticket" aria-label="Close ticket"><Icon name="check" /></button>
        </div><span class="status-badge" :class="statusClass(ticket.status)"><Icon name="circle" :size="12" />{{ ticket.status }}</span></div>
        <div ref="scroll" class="conversation-scroll">
            <div class="ticket-body-content">
                <div class="day-divider"><span>{{ new Date(ticket.created_at).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) }}</span></div>
                <article v-for="message in ticket.messages" :key="message.id" class="message-card" :class="{ outbound: message.kind === 'outbound', note: message.kind === 'note', automated: !!message.rule_name }">
                    <header>
                        <span v-if="message.rule_name" class="message-symbol"><Icon name="bolt" :size="18" /></span><span v-else class="avatar small" :class="{ self: message.kind !== 'inbound' }">{{ initials(message.author_name || message.author_email) }}</span>
                        <div class="message-author"><strong>{{ message.rule_name ? 'Automated message' : message.author_name || message.author_email || ticket.requester_email }}</strong><small v-if="message.kind === 'note'"><Icon name="lock" :size="11" />Private note · visible to your team</small><small v-else>to {{ message.kind === 'inbound' ? ticket.mailbox?.email || ticket.team?.name || 'Workspace' : ticket.requester_email }}</small></div>
                        <time :datetime="message.created_at">{{ dateTime(message.created_at) }}</time>
                    </header>
                    <small v-if="message.ticket_id !== ticket.id" class="merged-message-source">From merged ticket #{{ message.ticket_id }}</small>
                    <div v-if="message.kind !== 'note'" class="message-translation-tools"><span v-if="pendingTranslations[message.id]" role="status">Translating…</span><template v-else-if="message.translation"><span>{{ original[message.id] ? 'Original email' : languageName(message.translation.source_language) + ' → ' + languageName(message.translation.target_language) }}</span><button class="text-button" @click="original[message.id] = !original[message.id]; writtenOriginal[message.id] = false">{{ original[message.id] ? 'Show translation' : 'Show original' }}</button></template><button v-else class="text-button" @click="translateMessage(message, true).catch(() => {})">{{ translationErrors[message.id] ? 'Retry translation' : 'Translate email' }}</button><button v-if="message.original_body" class="text-button" @click="writtenOriginal[message.id] = !writtenOriginal[message.id]; if (!writtenOriginal[message.id]) original[message.id] = true">{{ writtenOriginal[message.id] ? 'Show sent email' : 'Show as written by agent' }}</button></div>
                    <p v-if="translationErrors[message.id]" class="translation-warning" role="alert">{{ translationErrors[message.id] }}</p>
                    <EmailMessageBody :html="writtenOriginal[message.id] ? message.original_body_html : message.translation && !original[message.id] ? message.translation.body_html : message.body_html" />
                    <div v-if="message.attachments?.length" class="message-files"><a v-for="(file, index) in message.attachments" :key="index" :href="file.url" class="attachment-link" download><Icon name="file" /><span>{{ file.name }}<small>{{ Math.ceil(file.size / 1024) }} KB</small></span><Icon name="download" :size="14" /></a></div>
                    <footer v-if="message.delivery || message.rule_name" :class="{ danger: message.delivery === 'failed' }"><span v-if="message.rule_name"><Icon name="bolt" :size="13" />{{ message.rule_name }}</span><span v-if="message.delivery" :title="message.delivery_error"><Icon :name="message.delivery === 'failed' ? 'alert' : 'check'" :size="13" />{{ { saved: 'Saved · email not connected', sent: 'Sent · SMTP accepted', delivered: 'Delivery confirmed', suppressed: 'Recipient suppressed · not sent', translation_pending: 'Awaiting browser translation · not sent', queued: 'Queued for delivery', sending: 'Sending', held: 'Held for review · not sent', failed: 'Delivery failed' }[message.delivery] }}</span><button v-if="['failed', 'held'].includes(message.delivery) && !state.workspace.sending_safety?.paused && !ticket.merged_into_id && message.ticket_id === ticket.id" class="text-button" @click="retryMessage(message)">{{ message.delivery === 'held' ? 'Release this reply' : 'Retry delivery' }}</button><button v-if="message.kind === 'outbound' && !message.attempt_id && ['translation_pending', 'held', 'saved'].includes(message.delivery) && !ticket.merged_into_id && message.ticket_id === ticket.id" class="text-button" @click="reviewTranslation(message)">Translate &amp; review reply</button><span v-if="message.opened_at" :title="dateTime(message.opened_at)">Image opened · read indication</span><button v-if="message.kind === 'outbound'" class="text-button" @click="deliveryMessage = message">Delivery details</button></footer>
                </article>
            </div>
        </div>
        <div v-if="!ticket.merged_into_id" class="composer-wrap"><div class="ticket-body-content">
            <form class="composer" :class="{ 'private-composer': privateNote }" @submit.prevent="send">
                <div class="composer-recipient"><span><Icon :name="privateNote ? 'lock' : 'mail'" :size="14" />{{ privateNote ? 'Private note for your team' : 'Reply to ' + ticket.requester_email }}</span><span class="draft-state">{{ draftState }}</span><button type="button" class="icon-button" @click="expanded = !expanded" :aria-label="expanded ? 'Collapse reply editor' : 'Expand reply editor'" :aria-expanded="expanded"><Icon :name="expanded ? 'minimize' : 'expand'" :size="15" /></button></div>
                <textarea ref="editor" v-model="body" :disabled="sending" class="reply-editor" :class="{ expanded }" :placeholder="privateNote ? 'Leave a note for your team…' : 'Enter message'" aria-label="Write a reply" @keydown="shortcut" @paste="pasteImage" @dragover.prevent @drop="dropImage" required />
                <div v-if="!privateNote" class="reply-translation-panel"><div class="translation-controls"><span><Icon name="globe" :size="14" />{{ requiresPreview ? 'Reply language: ' + languageName(ticket.customer_language?.language) : 'Reply translation is optional' }}</span><button type="button" class="text-button" @click="previewReply" :disabled="translating || sending || !body.trim()">{{ translating ? 'Translating…' : previewReady ? 'Translate again' : 'Preview translation' }}</button><button v-if="translationRequested && !autoReply" type="button" class="text-button" @click="translationRequested = false; preview = null" :disabled="sending">Use original reply</button></div><p v-if="requiresPreview && !previewReady && !translationError" class="muted">Your text stays in the editor. Review the translated preview before sending.</p><p v-if="translationError" class="error-message" role="alert">{{ translationError }} Nothing was sent.</p><div v-if="previewReady" class="reply-translation-preview"><label>Translated subject<input v-model="preview.subject" :disabled="sending" maxlength="500" /></label><label>Translated reply · review or edit<textarea v-model="preview.body" rows="4" dir="auto" :disabled="sending" /></label><small>Changes to your original reply, recipient, or language require a fresh translation. CC recipients receive the same language as the requester.</small></div></div>
                <div v-if="inlinePreviews.length" class="inline-previews"><button type="button" class="text-button" @click="imagePreview = !imagePreview">{{ imagePreview ? 'Hide' : 'Show' }} inline images ({{ inlinePreviews.length }})</button><div v-if="imagePreview"><img v-for="item in inlinePreviews" :key="item.url" :src="item.url" :alt="item.name" /></div></div>
                <div v-if="files.length" class="attached-files"><span v-for="(file, i) in files" :key="i"><Icon name="file" :size="13" />{{ file.name }}<button type="button" @click="files.splice(i, 1)" :aria-label="'Remove ' + file.name"><Icon name="x" :size="13" /></button></span></div>
                <div v-if="formatVisible" class="format-toolbar" aria-label="Message formatting"><button type="button" @click="format('**')" title="Bold"><b>B</b></button><button type="button" @click="format('_')" title="Italic"><i>I</i></button><button type="button" @click="format('~~')" title="Strikethrough"><s>S</s></button><button type="button" @click="format('&#96;')" title="Code">&lt;/&gt;</button><span class="divider" /><button type="button" @click="insert('\n1. ')" title="Numbered list">1.</button><button type="button" @click="insert('\n• ')" title="Bulleted list">•</button></div>
                <div class="composer-bottom"><div class="reply-options"><button type="button" class="icon-button" @click="inlineInput.click()" aria-label="Insert inline image" title="Insert inline image" :disabled="uploadingImage"><Icon :name="uploadingImage ? 'loader' : 'image'" /></button><label class="private-toggle"><input type="checkbox" v-model="privateNote" /><span class="toggle-track" />Private</label><span class="divider" /><button type="button" class="icon-button" @click="picker = true" aria-label="Insert canned response" title="Canned responses">#</button><button type="button" class="icon-button" @click="fileInput.click()" aria-label="Attach files" title="Attach files"><Icon name="attachment" /></button><button type="button" class="icon-button" @click="recording = true" aria-label="Screen recording design preview" title="Screen recording preview"><Icon name="video" /></button><button type="button" class="icon-button" @click="formatVisible = !formatVisible" title="Toggle formatting" aria-label="Toggle formatting"><u>A</u></button><button type="button" class="icon-button" @click="insert(state.user.preferences?.signature || '\n\nBest,\n' + state.user.name)" aria-label="Insert signature" title="Insert signature"><Icon name="edit" :size="15" /></button></div>
                <div class="send-options"><select v-model="sendStatus" aria-label="Status after reply"><option v-for="s in state.workspace.statuses" :key="s">{{ s }}</option></select><button class="primary-button" :disabled="sending || translating || uploadingImage || !body.trim() || (previewReady && (!preview.body.trim() || !preview.subject.trim()))"><Icon :name="privateNote ? 'lock' : 'send'" :size="15" />{{ translating ? 'Translating…' : sending ? 'Saving…' : privateNote ? 'Add note' : requiresPreview && !previewReady ? 'Translate reply' : state.workspace.sending_safety?.paused ? 'Save for review' : ticket.mailbox?.sending_enabled ? (requiresPreview ? 'Send translated reply' : 'Send reply') : 'Save reply' }}</button></div></div>
                <input ref="fileInput" type="file" multiple hidden @change="addFiles" />
                <input ref="inlineInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden @change="uploadImage($event.target.files[0])" />
            </form>
        </div></div>
    </section>
    <aside v-if="details" class="ticket-inspector">
        <header><h2>Ticket information</h2><button v-if="!ticket.merged_into_id" class="icon-button" @click="editDetails" aria-label="Edit ticket information"><Icon name="edit" :size="16" /></button><button class="icon-button inspector-close" @click="details = false" aria-label="Close ticket information"><Icon name="x" /></button></header>
        <div class="inspector-scroll">
            <section class="inspector-section inspector-tools"><h3>Conversation tools</h3><TicketTools :ticket="ticket" @refresh="refreshTools" /></section>
            <section class="inspector-section inspector-translation"><h3>Translation</h3><span class="reading-language"><Icon name="globe" :size="14" />Reading language: {{ languageName(translationSettings.target) }}</span><button v-if="ticket.subject_translation" class="text-button" @click="subjectOriginal = !subjectOriginal">{{ subjectOriginal ? 'Show translated subject' : 'Show original subject' }}</button><button class="text-button" @click="translateAll(true)">{{ translationErrors.all ? 'Retry email translation' : 'Translate all emails' }}</button><p v-if="translationErrors.all" class="error-message" role="alert">{{ translationErrors.all }} Originals remain available.</p></section>
        <fieldset class="inspector-fields" :disabled="Boolean(ticket.merged_into_id)">
            <section class="inspector-section">
                <label class="inspector-field"><span>Status</span><select :value="ticket.status" @change="update({ status: $event.target.value }).catch(() => {})" aria-label="Ticket status"><option v-for="s in state.workspace.statuses" :key="s">{{ s }}</option></select></label>
                <label class="inspector-field"><span>Priority</span><select :value="ticket.priority" @change="update({ priority: $event.target.value }).catch(() => {})" aria-label="Ticket priority"><option v-for="p in state.workspace.priorities" :key="p">{{ p }}</option></select></label>
                <div class="inspector-field"><span>Created</span><span>{{ new Date(ticket.created_at).toLocaleDateString() }}</span></div>
            </section>
            <section class="inspector-section"><h3>Responsibility</h3><label class="inspector-field"><span>Agent</span><select :value="ticket.assignee_id ?? ''" @change="update({ assignee_id: $event.target.value ? Number($event.target.value) : null }).catch(() => {})" aria-label="Ticket assignee"><option value="">Unassigned</option><option v-for="agent in state.workspace.agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option></select></label><label class="inspector-field"><span>Team</span><select :value="ticket.team_id ?? ''" @change="update({ team_id: $event.target.value ? Number($event.target.value) : null }).catch(() => {})" aria-label="Ticket team"><option value="">No team</option><option v-for="team in state.workspace.teams" :key="team.id" :value="team.id">{{ team.name }}</option></select></label></section>
            <section class="inspector-section"><h3>Tags</h3><div class="inspector-tags"><span v-for="tag in ticket.tags" :key="tag" class="tag">{{ tag }}<button @click="update({ tags: ticket.tags.filter(t => t !== tag) }, 'Tag removed').catch(() => {})" :aria-label="'Remove tag ' + tag"><Icon name="x" :size="12" /></button></span></div><form class="add-tag" @submit.prevent="addTag"><Icon name="plus" :size="14" /><input v-model="newTag" placeholder="Add a tag…" aria-label="Add a tag" maxlength="60" /></form></section>
            <section class="inspector-section"><h3>Requester</h3><div class="requester-card"><span class="avatar">{{ initials(ticket.requester_name || ticket.requester_email) }}</span><div><strong>{{ ticket.requester_name || ticket.requester_email }}</strong><small>{{ ticket.requester_email }}</small></div></div><span v-if="ticket.company" class="muted">{{ ticket.company }}</span></section>
            <section class="inspector-section customer-language"><h3>Customer language</h3><LanguagePicker :model-value="ticket.customer_language?.language" label="Customer language" automatic :disabled="detecting || sending" @update:model-value="setLanguage" /><small class="muted">{{ ticket.customer_language?.manual ? 'Selected manually' : 'Detected from the latest customer email' }} · saved for this email address</small><button type="button" class="text-button" @click="detectLanguage(true).catch(() => {})" :disabled="detecting || sending">{{ detecting ? 'Detecting…' : 'Detect language again' }}</button><p v-if="languageError" class="error-message" role="alert">{{ languageError }}</p></section>
            <section class="inspector-section"><h3>People in the loop<button @click="editDetails" class="icon-button" aria-label="Edit CC recipients"><Icon name="plus" :size="14" /></button></h3><span v-if="!ticket.cc?.length" class="muted">No additional recipients</span><div v-for="email in ticket.cc" :key="email" class="cc-email"><Icon name="mail" :size="13" />{{ email }}</div></section>
            <section class="inspector-section"><h3>Email source</h3><label class="inspector-field"><span>Mailbox</span><select :value="ticket.mailbox_id ?? ''" @change="changeMailbox" aria-label="Ticket mailbox" :disabled="sending || !sendingAccounts.length"><option v-if="mailboxUnavailable" :value="ticket.mailbox_id ?? ''" disabled>{{ ticket.mailbox?.name ? ticket.mailbox.name + ' · Sending disabled' : 'Choose a sending account' }}</option><option v-for="box in sendingAccounts" :key="box.id" :value="box.id">{{ box.name }}</option></select></label><small class="muted source-email">{{ ticket.mailbox?.email }}</small><div class="inspector-field"><span>Source</span><span aria-label="Ticket source">{{ ticket.source }}</span></div></section>
            <TicketCustomFields :ticket="ticket" :save-field="saveCustomField" />
            <section class="inspector-section"><h3>Related tickets</h3><Link v-for="item in related" :key="item.id" :href="$appUrl('/tickets/' + item.id)" class="related-ticket"><span>#{{ item.id }}</span>{{ item.subject }}<Icon name="right" :size="13" /></Link><span v-if="!related.length" class="muted">No other conversations</span></section>
            <section class="inspector-section"><h3>Recent activity</h3><div v-for="entry in activity.slice(0, 5)" :key="entry.id" class="small-activity"><i />{{ entry.description }}</div></section>
        </fieldset>
        </div>
    </aside>
</main>
<TranslationReview v-if="reviewMessage" :message="reviewMessage" :ticket="ticket" @close="reviewMessage = null" @saved="reviewedTranslation" />
<DeliveryDetails v-if="deliveryMessage" :message="deliveryMessage" @close="deliveryMessage = null" />
<Modal v-if="picker" title="Canned responses" @close="picker = false"><label class="modal-search"><Icon name="search" /><input v-model="replySearch" placeholder="Search responses or shortcuts…" aria-label="Search canned responses" /></label><button v-for="reply in canned" :key="reply.id" class="canned-picker-item" @click="insertReply(reply)"><div><strong>{{ reply.title }}</strong><kbd>{{ reply.shortcut }}</kbd></div><p>{{ reply.body }}</p></button><p v-if="!canned.length" class="muted">No matching responses. Create one in Canned responses.</p></Modal>
<Modal v-if="recording" title="Screen recording" @close="recording = false"><div class="recording-preview"><div class="recording-window"><div><i /><i /><i /></div><Icon name="video" :size="42" /><span>Your screen preview</span></div><span class="preview-badge">DESIGN PREVIEW</span><h3>A little context goes a long way.</h3><p>This is a preview of the recording interface. Screen capture, microphone access, and uploading are disabled.</p><button class="primary-button" @click="recording = false">Got it</button></div></Modal>
<Modal v-if="editing" title="Edit ticket information" wide @close="editing = false"><form class="form-stack" @submit.prevent="saveDetails"><label>Subject<input v-model="editForm.subject" required /></label><div class="form-grid"><label>Requester name<input v-model="editForm.requester_name" /></label><label>Email<input v-model="editForm.requester_email" type="email" required /></label></div><label>Company<input v-model="editForm.company" /></label><label>People in the loop<input v-model="editForm.cc" placeholder="Comma-separated email addresses" /></label><div class="form-actions"><button class="primary-button">Save changes</button></div></form></Modal>
</template>

<style>
.inspector-fields{border:0;padding:0;margin:0;min-width:0}
.inspector-tools .ticket-tools{display:grid;gap:13px;padding:0}.inspector-tools .ticket-tools button{justify-content:flex-start;font-size:11px}
.inspector-tools .similar-ticket-notice{margin:14px 0 0;padding:10px;font-size:11px;width:100%;text-align:start;line-height:1.5}
.inspector-translation{display:grid;gap:12px;font-size:11px}.inspector-translation h3{margin:0}.reading-language{display:flex;align-items:center;gap:6px;color:var(--muted)}.inspector-translation>.text-button{justify-self:start;font-size:11px}.inspector-translation .error-message{font-size:11px;line-height:1.6}
</style>
