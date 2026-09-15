<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { useNavigation } from '../useNavigation';
import { appUrl } from '../urls';
import { createTicketRefresher } from '../ticketRefresh';
import { ticketTimeline } from '../ticketTimeline';
import EmailMessageBody from '../components/EmailMessageBody.vue';
import ReplyEditor from '../components/ReplyEditor.vue';
import ReplyFormattingToolbar from '../components/ReplyFormattingToolbar.vue';
import { cannedShortcuts, matchesCannedShortcut, cannedShortcutTrigger, filterCannedReplies } from '../cannedReplies';
import { replyEditorHtml } from '../replyEditor';
import PriorityIcon from '../components/PriorityIcon.vue';
import { guardDraftNavigation } from '../draftNavigation';
import { state, api, notify, initials, statusClass, refreshSendingSafety } from '../store';
import Modal from '../components/Modal.vue';
import InspectorSection from '../components/InspectorSection.vue';
import { replyFormat, replyLink, automationAction } from '../replyFormatting';
import TicketTools from '../components/TicketTools.vue';
import TicketCustomFields from '../components/TicketCustomFields.vue';
import DeliveryDetails from '../components/DeliveryDetails.vue';
import LanguagePicker from '../components/LanguagePicker.vue';
import TranslationReview from '../components/TranslationReview.vue';
import { useTicketTranslation } from '../useTicketTranslation';
import { languageName, replyPayload, validateReplyPreview, messageNeedsTranslation } from '../translation';
const route = useNavigation();
const ticket = ref(null), related = ref([]), activity = ref([]), loading = ref(true), error = ref(''), refreshError = ref('');
const recentTickets = computed(() => [...new Map([...(ticket.value?.merged_tickets || []), ...(requesterHistory.value?.recent || [])].map(item => [item.id, item])).values()]);
const conversation = computed(() => ticketTimeline(ticket.value?.messages, activity.value));
const body = ref(''), privateNote = ref(false), expanded = ref(false), details = ref(window.innerWidth > 1100), sending = ref(false), draftState = ref(''), editor = ref(), scroll = ref(), files = ref([]), fileInput = ref();
const picker = ref(false), recording = ref(false), editing = ref(false), replySearch = ref(''), newTag = ref('');
const editForm = reactive({}), sendStatus = ref('Pending'), formatVisible = ref(true);
const replyAssignee = ref(undefined);
const ticketTools = ref(), requesterHistory = ref(null);
const linkDialog = ref(false), linkLabel = ref(''), linkAddress = ref(''), linkError = ref('');
let linkSelection = { start: 0, end: 0 };
const sendingAccounts = computed(() => state.workspace.mailboxes.filter(mailbox => mailbox.sending_enabled));
const mailboxUnavailable = computed(() => !sendingAccounts.value.some(mailbox => mailbox.id === ticket.value?.mailbox_id));
const inlineInput = ref(), uploadingImage = ref(false), deliveryMessage = ref(null);
const { enabled: translationEnabled, settings: translationSettings, original, errors: translationErrors, pending: pendingTranslations, translateMessage, translateAll, preview, previewReady, translating, translationError, prepare, prepareToSend, autoReply, automaticSend, detecting, languageError, detectLanguage, setLanguage } = useTicketTranslation(ticket, body, privateNote);
const translationRequested = ref(false), reviewMessage = ref(null), writtenOriginal = reactive({});
function showMessageTranslation(message) {
    return translationEnabled.value && messageNeedsTranslation(message, ticket.value?.customer_language?.language, translationSettings.value.target);
}
const requiresPreview = computed(() => translationEnabled.value && (autoReply.value || (!privateNote.value && translationRequested.value)));
const savingTranslation = ref(false);
watch(translationEnabled, () => { translationRequested.value = false; });
async function toggleTranslation() {
    savingTranslation.value = true;
    try { await update({ translation_enabled: !translationEnabled.value }, translationEnabled.value ? "Translation disabled for this ticket" : "Translation enabled for this ticket"); }
    catch {} finally { savingTranslation.value = false; }
}
const replyTranslationPanel = ref(), quickActionsOpen = ref(false);
async function showReplyTranslation() { details.value = true; await nextTick(); replyTranslationPanel.value?.reveal(); }
async function previewReply() { translationRequested.value = true; await showReplyTranslation(); await prepare(); }
async function reviewTranslation(message) { reviewMessage.value = message; }
async function reviewedTranslation() { reviewMessage.value = null; await refreshTicket(); notify('Reply prepared for delivery'); }
const canned = computed(() => state.workspace.replies.filter(r => (r.title + r.shortcut + r.body).toLowerCase().includes(replySearch.value.toLowerCase())));
const editorSelection = ref({ start: 0, end: 0 }), editorFocused = ref(false), suggestionIndex = ref(0), dismissedShortcut = ref('');
const replyEditorArea = ref(), suggestionsHeight = ref(680);
const shortcutTrigger = computed(() => editorSelection.value.start === editorSelection.value.end ? cannedShortcutTrigger(body.value, editorSelection.value.start) : null);
const shortcutKey = computed(() => shortcutTrigger.value ? JSON.stringify(shortcutTrigger.value) : '');
const suggestedReplies = computed(() => shortcutTrigger.value ? filterCannedReplies(state.workspace.replies, shortcutTrigger.value.query) : []);
const showSuggestions = computed(() => editorFocused.value && !sending.value && shortcutTrigger.value && dismissedShortcut.value !== shortcutKey.value);
function resizeSuggestions() {
    if (!showSuggestions.value || !replyEditorArea.value) return;
    const top = replyEditorArea.value.getBoundingClientRect().top;
    const pageTop = replyEditorArea.value.closest('.ticket-page')?.getBoundingClientRect().top || 0;
    suggestionsHeight.value = Math.max(0, Math.min(680, top - Math.max(0, pageTop) - 18));
}
watch([showSuggestions, expanded, body, formatVisible], () => nextTick(resizeSuggestions));
watch(shortcutKey, () => { suggestionIndex.value = 0; if (!shortcutKey.value) dismissedShortcut.value = ''; });
function chooseSuggestion(reply) {
    const trigger = shortcutTrigger.value;
    if (!trigger) return;
    insertReply(reply, trigger);
    dismissedShortcut.value = shortcutKey.value;
}
let draftTimer, deliveryTimer, draftPromise = Promise.resolve(), loaded = false, skipNextDraft = false;
let draftRevision = 0, savedDraftRevision = 0, ticketMutations = 0;
const hasUnsavedDraft = () => loaded && !ticket.value?.merged_into_id && draftRevision !== savedDraftRevision;
const dateTime = date => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: state.workspace.settings.general?.timezone || undefined }).format(new Date(date));
async function load() {
    try {
        const data = await api('tickets/' + route.params.id);
        ticket.value = data.ticket; related.value = data.related; activity.value = data.activity;
        const firstReply = data.ticket.messages.find(message => message.id === Number(route.query.prepare_reply) && message.kind === 'outbound' && !message.rule_name && message.delivery === 'translation_pending' && !message.attempt_id);
        if (firstReply) reviewMessage.value = firstReply;
        sendStatus.value = data.draft?.private ? data.ticket.status : 'Pending';
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
watch(privateNote, value => { sendStatus.value = value ? ticket.value?.status || 'Open' : 'Pending'; });
function beforeUnload(event) { if (hasUnsavedDraft() || sending.value) { event.preventDefault(); event.returnValue = ''; } }
onBeforeUnmount(() => {
    window.removeEventListener('resize', resizeSuggestions); document.removeEventListener('scroll', resizeSuggestions, true);
    ticketRefresher.dispose(); document.removeEventListener('visibilitychange', refreshVisibleTicket);
    window.removeEventListener('focus', refreshVisibleTicket); window.removeEventListener('online', refreshVisibleTicket);
    removeDraftGuard(); clearTimeout(draftTimer); clearInterval(deliveryTimer); window.removeEventListener('beforeunload', beforeUnload);
    if (hasUnsavedDraft() && !sending.value) saveDraft().catch(e => notify('Couldn’t save your draft. ' + e.message, true));
});
const ticketRefresher = createTicketRefresher({
    getId: () => route.params.id,
    fetchTicket: id => api('tickets/' + id, { cache: 'no-store', signal: AbortSignal.timeout(20000) }),
    apply: result => {
        const atBottom = !scroll.value || scroll.value.scrollHeight - scroll.value.scrollTop - scroll.value.clientHeight < 100;
        const changed = ticket.value?.messages.at(-1)?.id !== result.ticket.messages.at(-1)?.id
            || activity.value.at(-1)?.id !== result.activity.at(-1)?.id;
        ticket.value = result.ticket; related.value = result.related; activity.value = result.activity;
        refreshError.value = '';
        if (changed && atBottom) nextTick(scrollBottom);
    },
});
function refreshTicket() { return ticketRefresher.refresh(); }
function refreshVisibleTicket() {
    if (loaded && !sending.value && !ticketMutations && !document.hidden) {
        refreshTicket().catch(() => { refreshError.value = 'Couldn’t check for new messages. Retrying automatically.'; });
    }
}
watch(() => state.refresh, refreshVisibleTicket);
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload); window.addEventListener('resize', resizeSuggestions); document.addEventListener('scroll', resizeSuggestions, true); document.addEventListener('visibilitychange', refreshVisibleTicket); window.addEventListener('focus', refreshVisibleTicket); window.addEventListener('online', refreshVisibleTicket); deliveryTimer = setInterval(refreshVisibleTicket, 30000); });
async function uploadImage(file) {
    if (!file || uploadingImage.value) return;
    if (!['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { notify('Choose a PNG, JPEG, GIF, or WebP image up to 5 MB.', true); return; }
    uploadingImage.value = true;
    try { const data = new FormData(); data.append('image', file); const result = await api('tickets/' + ticket.value.id + '/inline-images', { method: 'POST', body: data }); insert('\n' + result.markdown + '\n'); notify('Image inserted into your reply'); }
    catch (e) { notify(e.message, true); } finally { uploadingImage.value = false; if (inlineInput.value) inlineInput.value.value = ''; }
}
async function update(changes, message = 'Ticket updated') {
    ticketMutations++;
    ticketRefresher.invalidate();
    try { const result = await api('tickets/' + ticket.value.id, { method: 'PATCH', body: changes }); ticketRefresher.invalidate(); Object.assign(ticket.value, result.data); if (Object.hasOwn(changes, 'assignee_id')) replyAssignee.value = result.data.assignee_id; if (changes.status && privateNote.value) sendStatus.value = result.data.status; state.refresh++; if (message) notify(message); }
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
async function send(sendOriginal = false) {
    if (!body.value.trim() || sending.value || savingTranslation.value || translating.value || uploadingImage.value) return;
    if (ticketMutations) { notify('Wait for the ticket changes to finish saving, then send.', true); return; }
    sendOriginal = (sendOriginal === true || !translationEnabled.value) && !privateNote.value;
    if (sendOriginal && autoReply.value && ticket.value.customer_language?.language) { notify('Translate this reply into the customer language before sending.', true); return; }
    if (!sendOriginal && !await prepareToSend(requiresPreview.value)) {
        await showReplyTranslation();
        if (previewReady.value) notify('Translation ready. Review the preview, then send.');
        return;
    }
    const originalFallback = !privateNote.value && previewReady.value && preview.value.sendOriginal;
    sendOriginal ||= originalFallback;
    const useTranslation = requiresPreview.value && !sendOriginal;
    const sendingOriginal = body.value, sendingPrivate = privateNote.value;
    sending.value = true; ticketRefresher.invalidate(); clearTimeout(draftTimer);
    try {
        await draftPromise.catch(() => {});
        if (body.value !== sendingOriginal || privateNote.value !== sendingPrivate || ((useTranslation || originalFallback) && !previewReady.value)) throw new Error('The reply changed. Review it before sending.');
        if (useTranslation) validateReplyPreview(preview.value);
        const data = new FormData(); data.append('body', useTranslation ? preview.value.body : body.value); data.append('private', privateNote.value ? '1' : '0'); data.append('status', sendStatus.value);
        if (!privateNote.value && replyAssignee.value !== undefined) data.append('assignee_id', replyAssignee.value ?? '');
        if (useTranslation) data.append('translation', JSON.stringify(replyPayload(preview.value)));
        if (sendOriginal) data.append('send_original', '1');
        files.value.forEach(file => data.append('attachments[]', file));
        const result = await api('tickets/' + ticket.value.id + '/messages', { method: 'POST', body: data });
        ticket.value = result.data; savedDraftRevision = draftRevision; skipNextDraft = true; body.value = ''; translationRequested.value = false; files.value = []; draftState.value = ''; state.refresh++; await refreshTicket();
        sendStatus.value = privateNote.value ? ticket.value.status : 'Pending';
        const delivery = ticket.value.messages.at(-1)?.delivery;
        notify(privateNote.value ? 'Private note added' : delivery === 'held' ? 'Reply held. Sending is paused for review.' : delivery === 'queued' ? 'Reply queued for delivery' : 'Reply saved. This mailbox is not connected.');
        refreshSendingSafety().catch(() => {});
        setTimeout(scrollBottom, 0);
    } catch (e) { notify(e.message, true); } finally { sending.value = false; }
}
function insert(text) {
    if (editor.value) editor.value.insert(text);
    else body.value += text;
}
function format(before, after = before) {
    const start = editor.value?.selectionStart || 0, end = editor.value?.selectionEnd || 0;
    insert(replyFormat(body.value.slice(start, end), before, after));
}
function openLinkDialog() {
    linkSelection = { start: editor.value?.selectionStart ?? body.value.length, end: editor.value?.selectionEnd ?? body.value.length };
    linkLabel.value = body.value.slice(linkSelection.start, linkSelection.end); linkAddress.value = ''; linkError.value = ''; linkDialog.value = true;
}
async function insertLink() {
    try {
        const markdown = replyLink(linkLabel.value, linkAddress.value);
        const start = linkSelection.start;
        body.value = body.value.slice(0, start) + markdown + body.value.slice(linkSelection.end);
        linkDialog.value = false; await nextTick(); editor.value?.focus(); editor.value?.setSelectionRange(start + markdown.length, start + markdown.length);
    } catch (error) { linkError.value = error.message; }
}
function insertReply(reply, range = null) {
    let text = reply.body;
    const vars = { name: ticket.value.requester_name || ticket.value.requester_email, email: ticket.value.requester_email, ticket_id: ticket.value.id, subject: ticket.value.subject, agent: state.user.name };
    Object.entries(vars).forEach(([key, value]) => { text = text.replaceAll('{{' + key + '}}', value); });
    if (range) {
        editor.value?.setSelectionRange(range.start, range.end);
    } else if (matchesCannedShortcut(reply, body.value)) {
        editor.value?.setSelectionRange(0, body.value.length);
    }
    insert(text); picker.value = false;
}
function shortcut(e) {
    if (e.isComposing) return;
    if (showSuggestions.value) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); dismissedShortcut.value = shortcutKey.value; return; }
        if (suggestedReplies.value.length && ['ArrowDown', 'ArrowUp'].includes(e.key)) {
            e.preventDefault();
            suggestionIndex.value = (suggestionIndex.value + (e.key === 'ArrowDown' ? 1 : -1) + suggestedReplies.value.length) % suggestedReplies.value.length;
            nextTick(() => document.getElementById('canned-suggestion-' + suggestionIndex.value)?.scrollIntoView({ block: 'nearest' }));
            return;
        }
        if (suggestedReplies.value.length && ['Enter', 'Tab'].includes(e.key) && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
            e.preventDefault(); chooseSuggestion(suggestedReplies.value[suggestionIndex.value]); return;
        }
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
    if (e.key === 'Tab') { const reply = state.workspace.replies.find(r => matchesCannedShortcut(r, body.value)); if (reply) { e.preventDefault(); insertReply(reply); } }
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
        <header class="ticket-subject-row"><Link class="icon-button" :href="$appUrl('/tickets')" aria-label="Back to inbox"><Icon name="back" /></Link><h1 @dblclick="editDetails" :title="ticket.subject" dir="auto"><PriorityIcon :priority="ticket.priority" />{{ ticket.subject }}</h1>
        <button v-if="!ticket.merged_into_id" class="icon-button ticket-actions-toggle" @click="quickActionsOpen = !quickActionsOpen" aria-label="Ticket actions" :aria-expanded="quickActionsOpen" aria-controls="ticket-quick-actions"><Icon name="more" /></button>
        <div v-if="!ticket.merged_into_id" id="ticket-quick-actions" class="ticket-quickbar" :class="{ 'is-open': quickActionsOpen }" @click="quickActionsOpen = false" @keydown.esc="quickActionsOpen = false"><div>
            <Link :href="$appUrl('/automations')" class="icon-button" title="Automations" aria-label="Automations"><Icon name="bolt" /></Link>
            <button class="icon-button" @click="picker = true" title="Canned responses" aria-label="Canned responses"><Icon name="message" /></button><span class="divider" />
            <button v-if="ticket.folder !== 'inbox'" class="icon-button" @click="move('inbox')" title="Restore to inbox" aria-label="Restore to inbox"><Icon name="inbox" /></button>
            <button class="icon-button" @click="move('archive')" title="Archive" aria-label="Archive ticket"><Icon name="archive" /></button>
            <button class="icon-button" @click="move('spam')" title="Mark spam" aria-label="Mark as spam"><Icon name="spam" /></button>
            <button class="icon-button" @click="move('trash')" title="Move to Trash" aria-label="Move to Trash"><Icon name="trash" /></button><span class="divider" />
            <button class="icon-button" @click="markUnread" title="Mark unread" aria-label="Mark unread"><Icon name="mail" /></button>
            <button class="icon-button" @click="update({ status: 'Closed' }).catch(() => {})" title="Close ticket" aria-label="Close ticket"><Icon name="check" /></button>
        </div></div><span class="status-badge" :class="statusClass(ticket.status)"><Icon name="circle" :size="12" />{{ ticket.status }}</span><button class="icon-button" @click="details = !details" aria-label="Toggle ticket information" :aria-expanded="details"><Icon name="panel" /></button></header>
        <div v-if="ticket.merged_into_id" class="folder-notice merged-notice"><Icon name="merged" /> Merged into <Link :href="$appUrl('/tickets/' + ticket.merged_into_id)">{{ ticket.merged_parent?.subject || 'Open main conversation' }}</Link>. This ticket is read only. New customer replies appear in the main ticket.</div>
        <div v-if="ticket.email_opt_outs?.length" class="folder-notice" role="status"><Icon name="mail" /><span>Automatic and scheduled email notifications are disabled for {{ ticket.email_opt_outs.join(', ') }}. Only direct agent replies will be sent.</span></div>
        <div ref="scroll" class="conversation-scroll">
            <div class="ticket-body-content">
                <p v-if="refreshError" class="error-message" role="status">{{ refreshError }} <button type="button" class="text-button" @click="refreshVisibleTicket">Try now</button></p>
                <div class="day-divider"><span>{{ new Date(ticket.created_at).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) }}</span></div>
                <template v-for="{ message, entry, key } in conversation" :key="key">
                <div v-if="entry" class="conversation-event conversation-activity"><p><strong v-if="entry.user?.name">{{ entry.user.name }} · </strong>{{ entry.description }}</p><time :datetime="entry.created_at">{{ dateTime(entry.created_at) }}</time></div>
                <template v-else>
                <div v-if="message.rule_name" class="conversation-event"><Icon name="bolt" :size="14" /><p>Automation <Link :href="$appUrl('/automations') + '?rule=' + encodeURIComponent(message.rule_name)">{{ message.rule_name }}</Link> {{ automationAction(message) }}<template v-if="message.kind !== 'note'"> to <strong>{{ ticket.requester_name || ticket.requester_email }}</strong></template>.</p><time :datetime="message.created_at">{{ dateTime(message.created_at) }}</time></div>
                <article class="message-card" :class="{ outbound: message.kind === 'outbound', note: message.kind === 'note', automated: !!message.rule_name }">
                    <header>
                        <span v-if="message.rule_name" class="message-symbol"><Icon name="bolt" :size="18" /></span><span v-else class="avatar small" :class="{ self: message.kind !== 'inbound' }">{{ initials(message.author_name || message.author_email) }}</span>
                        <div class="message-author"><strong>{{ message.rule_name ? 'Automated message' : message.author_name || message.author_email || ticket.requester_email }}</strong><small v-if="message.kind === 'note'"><Icon name="lock" :size="11" />Private note · visible to your team</small><div v-else-if="message.id === ticket.messages[0]?.id" class="message-reply-addresses" aria-label="Reply addresses"><small><Icon name="mail" :size="12" />From: <span dir="ltr">{{ ticket.mailbox?.email || 'No mailbox selected' }}</span></small><small><Icon name="send" :size="12" />Reply to: <span dir="ltr">{{ ticket.requester_email }}</span></small></div><small v-else>to {{ message.kind === 'inbound' ? ticket.mailbox?.email || ticket.team?.name || 'Workspace' : ticket.requester_email }}</small></div>
                        <time :datetime="message.created_at">{{ dateTime(message.created_at) }}</time>
                    </header>
                    <small v-if="message.ticket_id !== ticket.id" class="merged-message-source">From a merged ticket</small>
                    <div v-if="showMessageTranslation(message)" class="message-translation-tools"><span v-if="pendingTranslations[message.id]" role="status">Translating…</span><template v-else-if="message.translation"><span>{{ original[message.id] ? 'Original email' : languageName(message.translation.source_language) + ' → ' + languageName(message.translation.target_language) }}</span><button class="text-button" @click="original[message.id] = !original[message.id]; writtenOriginal[message.id] = false">{{ original[message.id] ? 'Show translation' : 'Show original' }}</button></template><button v-else class="text-button" @click="translateMessage(message, true).catch(() => {})">{{ translationErrors[message.id] ? 'Retry translation' : 'Translate email' }}</button><button v-if="message.original_body" class="text-button" @click="writtenOriginal[message.id] = !writtenOriginal[message.id]; if (!writtenOriginal[message.id]) original[message.id] = true">{{ writtenOriginal[message.id] ? 'Show sent email' : 'Show as written by agent' }}</button></div>
                    <p v-if="showMessageTranslation(message) && translationErrors[message.id]" class="translation-warning" role="alert">{{ translationErrors[message.id] }}</p>
                    <EmailMessageBody :html="showMessageTranslation(message) && writtenOriginal[message.id] ? message.original_body_html : showMessageTranslation(message) && message.translation && !original[message.id] ? message.translation.body_html : message.body_html" />
                    <div v-if="message.attachments?.length" class="message-files"><a v-for="(file, index) in message.attachments" :key="index" :href="file.url" class="attachment-link" download><Icon name="file" /><span>{{ file.name }}<small>{{ Math.ceil(file.size / 1024) }} KB</small></span><Icon name="download" :size="14" /></a></div>
                    <footer v-if="message.delivery" :class="{ danger: message.delivery === 'failed' }"><span v-if="message.delivery" :title="message.delivery_error"><Icon :name="message.delivery === 'failed' ? 'alert' : 'check'" :size="13" />{{ { saved: 'Saved · email not connected', sent: 'Sent · SMTP accepted', delivered: 'Delivery confirmed', suppressed: 'Recipient suppressed · not sent', translation_pending: message.rule_name ? (message.delivery_error?.startsWith('Background translation failed') ? 'Translation failed · retrying automatically' : 'Translating in the background · will send automatically') : 'Awaiting browser translation · not sent', queued: 'Queued for delivery', sending: 'Sending', held: 'Held for review · not sent', failed: 'Delivery failed' }[message.delivery] }}</span><button v-if="['failed', 'held'].includes(message.delivery) && !state.workspace.sending_safety?.paused && !ticket.merged_into_id && message.ticket_id === ticket.id" class="text-button" @click="retryMessage(message)">{{ message.delivery === 'held' ? 'Release this reply' : 'Retry delivery' }}</button><button v-if="message.kind === 'outbound' && !message.rule_name && !message.attempt_id && ['translation_pending', 'held', 'saved'].includes(message.delivery) && !ticket.merged_into_id && message.ticket_id === ticket.id" class="text-button" @click="reviewTranslation(message)">{{ !translationEnabled ? 'Review original reply' : translationSettings.outgoing && translationSettings.auto_send ? 'Translate & send reply' : 'Translate & review reply' }}</button><span v-if="message.opened_at" :title="dateTime(message.opened_at)">Email opened</span><button v-if="message.kind === 'outbound'" class="text-button" @click="deliveryMessage = message">Message status</button></footer>
                </article>
                </template>
                </template>
                <nav v-if="ticket.merged_tickets?.length" class="merged-ticket-links" aria-label="Merged tickets"><Link v-for="item in ticket.merged_tickets" :key="item.id" :href="$appUrl('/tickets/' + item.id)" :title="'View original messages: ' + item.subject"><Icon name="merged" :size="18" /><span dir="auto">{{ item.subject }}</span><span class="status-badge" :class="statusClass(item.status)">{{ item.status }}</span></Link></nav>
            </div>
        </div>
        <div v-if="!ticket.merged_into_id" class="composer-wrap" :class="{ 'suggestions-open': showSuggestions }"><div class="ticket-body-content">
            <form class="composer" :class="{ 'private-composer': privateNote, 'suggestions-open': showSuggestions }" @submit.prevent="send">
                <div class="composer-recipient"><span><template v-if="privateNote"><Icon name="lock" :size="14" />Private note for your team</template></span><span class="draft-state">{{ draftState }}</span><button type="button" class="icon-button" @click="expanded = !expanded" :aria-label="expanded ? 'Collapse reply editor' : 'Expand reply editor'" :aria-expanded="expanded"><Icon :name="expanded ? 'minimize' : 'expand'" :size="15" /></button></div>
                <div ref="replyEditorArea" class="reply-editor-area">
                    <div v-if="showSuggestions" id="canned-suggestions" class="canned-suggestions" :style="{ maxHeight: suggestionsHeight + 'px' }" role="listbox" aria-label="Matching canned responses" @mousedown.prevent>
                        <button v-for="(reply, index) in suggestedReplies" :id="'canned-suggestion-' + index" :key="reply.id" type="button" role="option" :aria-selected="index === suggestionIndex" :class="{ selected: index === suggestionIndex }" :title="cannedShortcuts(reply.shortcut).join(', ')" @mouseenter="suggestionIndex = index" @click="chooseSuggestion(reply)">
                            <span class="canned-suggestion-title">{{ reply.title }}</span><span class="canned-suggestion-count" aria-label="1 response">1</span><Icon name="right" :size="17" />
                        </button>
                        <p v-if="!suggestedReplies.length" class="canned-suggestions-empty" role="status">No matching canned responses.</p>
                    </div>
                    <ReplyEditor ref="editor" v-model="body" :disabled="sending" :expanded="expanded" :placeholder="privateNote ? 'Leave a note for your team…' : 'Enter message'" :aria-expanded="Boolean(showSuggestions)" :aria-controls="showSuggestions ? 'canned-suggestions' : undefined" :aria-activedescendant="showSuggestions && suggestedReplies.length ? 'canned-suggestion-' + suggestionIndex : undefined" aria-autocomplete="list" @focus="editorFocused = true" @blur="editorFocused = false" @selection="editorSelection = $event" @keydown="shortcut" @image="uploadImage" />
                </div>
                <div v-if="files.length" class="attached-files"><span v-for="(file, i) in files" :key="i"><Icon name="file" :size="13" />{{ file.name }}<button type="button" @click="files.splice(i, 1)" :aria-label="'Remove ' + file.name"><Icon name="x" :size="13" /></button></span></div>
                <ReplyFormattingToolbar v-if="formatVisible" :disabled="sending" :uploading-image="uploadingImage" @format="format" @insert="insert" @link="openLinkDialog" @image="inlineInput.click()" />
                <div class="composer-bottom"><div class="reply-options"><button type="button" class="icon-button" @click="inlineInput.click()" aria-label="Insert inline image" title="Insert inline image" :disabled="uploadingImage"><Icon :name="uploadingImage ? 'loader' : 'image'" /></button><label class="private-toggle"><input type="checkbox" v-model="privateNote" /><span class="toggle-track" />Private</label><span class="divider" /><button type="button" class="icon-button" @click="picker = true" aria-label="Insert canned response" title="Canned responses">#</button><button type="button" class="icon-button" @click="fileInput.click()" aria-label="Attach files" title="Attach files"><Icon name="attachment" /></button><button type="button" class="icon-button" @click="recording = true" aria-label="Screen recording design preview" title="Screen recording preview"><Icon name="video" /></button><button type="button" class="icon-button" @click="formatVisible = !formatVisible" title="Toggle formatting" aria-label="Toggle formatting"><u>A</u></button><button type="button" class="icon-button" @click="insert(state.user.preferences?.signature || '\n\nBest,\n' + state.user.name)" aria-label="Insert signature" title="Insert signature"><Icon name="edit" :size="15" /></button></div>
                <div class="send-options"><select v-model="sendStatus" aria-label="Status after reply"><option v-for="s in state.workspace.statuses" :key="s">{{ s }}</option></select><button class="primary-button" :disabled="sending || translating || uploadingImage || !body.trim() || (previewReady && (!preview.body.trim() || !preview.subject.trim()))"><Icon :name="privateNote ? 'lock' : 'send'" :size="15" />{{ translating ? 'Translating…' : sending ? 'Saving…' : privateNote ? 'Add note' : requiresPreview && !previewReady ? (automaticSend ? 'Translate & send reply' : 'Translate reply') : state.workspace.sending_safety?.paused ? 'Save for review' : ticket.mailbox?.sending_enabled ? (requiresPreview && !preview?.sameLanguage ? 'Send translated reply' : 'Send reply') : 'Save reply' }}</button></div></div>
                <input ref="fileInput" type="file" multiple hidden @change="addFiles" />
                <input ref="inlineInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden @change="uploadImage($event.target.files[0])" />
            </form>
        </div></div>
    </section>
    <aside v-if="details" class="ticket-inspector">
        <header><h2>Ticket information</h2><button v-if="!ticket.merged_into_id" class="icon-button" @click="editDetails" aria-label="Edit ticket information"><Icon name="edit" :size="16" /></button><button class="icon-button inspector-close" @click="details = false" aria-label="Close ticket information"><Icon name="x" /></button></header>
        <div class="inspector-scroll">
            <InspectorSection title="Translation" class="inspector-section inspector-translation">
                <div class="ticket-translation-toggle"><span id="ticket-translation-label">Translate this ticket</span><button type="button" class="translation-switch" role="switch" :aria-checked="translationEnabled" aria-labelledby="ticket-translation-label" :disabled="savingTranslation || sending || Boolean(ticket.merged_into_id)" @click="toggleTranslation"><span /></button></div>
                <p v-if="savingTranslation" class="muted" role="status">Saving…</p>
                <p v-else-if="!translationEnabled" class="muted">Translation is off. View original messages and send replies as written.</p>
                <template v-if="translationEnabled"><span class="reading-language"><Icon name="globe" :size="14" />Reading language: {{ languageName(translationSettings.target) }}</span><button class="text-button" @click="translateAll(true)">{{ translationErrors.all ? 'Retry email translation' : 'Translate all emails' }}</button><p v-if="translationErrors.all" class="error-message" role="alert">{{ translationErrors.all }} Originals remain available.</p></template>
            </InspectorSection>

            <InspectorSection title="Reply translation" v-if="translationEnabled && !privateNote && !ticket.merged_into_id" ref="replyTranslationPanel" class="inspector-section reply-translation-panel" aria-label="Reply translation"><div class="translation-controls"><span><Icon name="globe" :size="14" />Reply language: {{ languageName(ticket.customer_language?.language) }}</span><button type="button" class="text-button" @click="automaticSend && translationError ? send() : previewReply()" :disabled="translating || sending || !body.trim()">{{ translating ? 'Translating…' : translationError ? 'Retry translation' : previewReady ? 'Translate again' : 'Preview translation' }}</button><button v-if="translationRequested && !autoReply" type="button" class="text-button" @click="translationRequested = false; preview = null" :disabled="sending">Use original reply</button></div><p v-if="requiresPreview && !previewReady && !translationError" class="muted">{{ automaticSend ? 'Click Send to translate and send in the customer’s language automatically.' : 'Matching languages send your original reply. Otherwise, review the translated preview before sending.' }}</p><p v-else-if="!requiresPreview" class="muted">Reply translation is optional.</p><p v-if="translationError" class="error-message" role="alert">{{ translationError }} Nothing was sent.</p><button v-if="translationError && !autoReply" type="button" class="text-button" @click="send(true)" :disabled="sending || translating || uploadingImage">Send original language</button><div v-if="previewReady && !preview.sendOriginal" class="reply-translation-preview"><label>{{ preview.sameLanguage ? "Original reply · already in the customer’s language" : "Translated reply · review or edit" }}<textarea v-model="preview.body" rows="6" dir="auto" :disabled="sending" :readonly="preview.sameLanguage" /></label><small>Changes to your original reply, recipient, or language require a fresh translation. CC recipients receive the same language as the requester.</small><button type="button" class="primary-button" @click="send()" :disabled="sending || translating || uploadingImage || !preview.body.trim() || !preview.subject.trim()">{{ sending ? 'Saving…' : state.workspace.sending_safety?.paused ? 'Save for review' : ticket.mailbox?.sending_enabled ? (preview.sameLanguage ? 'Send original reply' : 'Send translated reply') : 'Save reply' }}</button></div></InspectorSection>
            <InspectorSection title="Conversation tools" class="inspector-section inspector-tools"><TicketTools ref="ticketTools" :ticket="ticket" @refresh="refreshTools" @history="requesterHistory = $event" /></InspectorSection>
        <fieldset class="inspector-fields" :disabled="Boolean(ticket.merged_into_id)">
            <InspectorSection title="Ticket info" class="inspector-section">
                <label class="inspector-field"><span>Status</span><select :value="ticket.status" @change="update({ status: $event.target.value }).catch(() => {})" aria-label="Ticket status"><option v-for="s in state.workspace.statuses" :key="s">{{ s }}</option></select></label>
                <label class="inspector-field"><span>Priority</span><select :value="ticket.priority" @change="update({ priority: $event.target.value }).catch(() => {})" aria-label="Ticket priority"><option v-for="p in state.workspace.priorities" :key="p">{{ p }}</option></select></label>
                <div class="inspector-field"><span>Created</span><span>{{ new Date(ticket.created_at).toLocaleDateString() }}</span></div>
            </InspectorSection>
            <InspectorSection v-if="ticket.custom_field_definitions?.length" title="Custom fields"><TicketCustomFields :ticket="ticket" :save-field="saveCustomField" /></InspectorSection>
            <InspectorSection title="Responsibility" class="inspector-section"><label class="inspector-field"><span>Agent</span><select :value="ticket.assignee_id ?? ''" @change="update({ assignee_id: $event.target.value ? Number($event.target.value) : null }).catch(() => {})" aria-label="Ticket assignee" :disabled="sending"><option value="">Unassigned</option><option v-for="agent in state.workspace.agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option></select></label><label class="inspector-field"><span>Team</span><select :value="ticket.team_id ?? ''" @change="update({ team_id: $event.target.value ? Number($event.target.value) : null }).catch(() => {})" aria-label="Ticket team"><option value="">No team</option><option v-for="team in state.workspace.teams" :key="team.id" :value="team.id">{{ team.name }}</option></select></label></InspectorSection>
            <InspectorSection title="Tags" class="inspector-section"><div class="inspector-tags"><span v-for="tag in ticket.tags" :key="tag" class="tag">{{ tag }}<button @click="update({ tags: ticket.tags.filter(t => t !== tag) }, 'Tag removed').catch(() => {})" :aria-label="'Remove tag ' + tag"><Icon name="x" :size="12" /></button></span></div><form class="add-tag" @submit.prevent="addTag"><Icon name="plus" :size="14" /><input v-model="newTag" placeholder="Add a tag…" aria-label="Add a tag" maxlength="60" /></form></InspectorSection>
            <InspectorSection title="Requester" class="inspector-section"><div class="requester-card"><span class="avatar">{{ initials(ticket.requester_name || ticket.requester_email) }}</span><div><strong>{{ ticket.requester_name || ticket.requester_email }}</strong><small>{{ ticket.requester_email }}</small></div></div><span v-if="ticket.company" class="muted">{{ ticket.company }}</span></InspectorSection>
            <InspectorSection v-if="translationEnabled" title="Customer language" class="inspector-section customer-language"><LanguagePicker :model-value="ticket.customer_language?.language" label="Customer language" automatic :disabled="detecting || sending" @update:model-value="setLanguage" /><small class="muted">{{ ticket.customer_language?.manual ? 'Selected manually' : 'Detected from the latest customer email' }} · saved for this email address</small><button type="button" class="text-button" @click="detectLanguage(true).catch(() => {})" :disabled="detecting || sending">{{ detecting ? 'Detecting…' : 'Detect language again' }}</button><p v-if="languageError" class="error-message" role="alert">{{ languageError }}</p></InspectorSection>
            <InspectorSection title="People in the loop" class="inspector-section"><button @click="editDetails" class="icon-button" aria-label="Edit CC recipients"><Icon name="plus" :size="14" /></button><span v-if="!ticket.cc?.length" class="muted">No additional recipients</span><div v-for="email in ticket.cc" :key="email" class="cc-email"><Icon name="mail" :size="13" />{{ email }}</div></InspectorSection>
            <InspectorSection title="Email source" class="inspector-section"><label class="inspector-field"><span>Mailbox</span><select :value="ticket.mailbox_id ?? ''" @change="changeMailbox" aria-label="Ticket mailbox" :disabled="sending || !sendingAccounts.length"><option v-if="mailboxUnavailable" :value="ticket.mailbox_id ?? ''" disabled>{{ ticket.mailbox?.name ? ticket.mailbox.name + ' · Sending disabled' : 'Choose a sending account' }}</option><option v-for="box in sendingAccounts" :key="box.id" :value="box.id">{{ box.name }}</option></select></label><small class="muted source-email">{{ ticket.mailbox?.email }}</small><div class="inspector-field"><span>Source</span><span aria-label="Ticket source">{{ ticket.source }}</span></div></InspectorSection>
        </fieldset>
            <InspectorSection title="Requester’s tickets" class="requester-tickets">
                <div class="requester-tickets-heading"><strong>Recent tickets</strong><button v-if="!ticket.merged_into_id" type="button" class="text-button" @click="ticketTools?.openMerge()">Merge</button></div>
                <p v-if="!requesterHistory" class="muted">Loading requester’s tickets…</p><p v-else-if="requesterHistory.error" class="error-message" role="alert">{{ requesterHistory.error }} <button type="button" class="text-button" @click="ticketTools?.openHistory(false)">Retry</button></p>
                <div v-for="item in recentTickets" :key="item.id" class="requester-ticket-row"><span class="status-badge" :class="statusClass(item.status)">{{ item.status }}</span><Link :href="$appUrl('/tickets/' + item.id)">{{ item.subject }}<span v-if="item.merged_into_id" class="merge-indicator" :title="'Merged into another ticket'" :aria-label="'Merged into another ticket'"><Icon name="merged" :size="16" /></span></Link></div>
                <p v-if="requesterHistory && !requesterHistory.error && !recentTickets.length" class="muted">No other recent tickets.</p>
                <div class="requester-archive"><strong>Archived tickets</strong><p><button type="button" class="text-button" @click="ticketTools?.openHistory(true)">Search Archive</button> to view this requester’s archived tickets.</p></div>
            </InspectorSection>
        </div>
    </aside>
</main>
<TranslationReview v-if="reviewMessage" :message="reviewMessage" :ticket="ticket" :assignee-id="replyAssignee" :detect-language="detectLanguage" @close="reviewMessage = null" @saved="reviewedTranslation" />
<DeliveryDetails v-if="deliveryMessage" :message="deliveryMessage" @close="deliveryMessage = null" />
<Modal v-if="picker" title="Canned responses" @close="picker = false"><label class="modal-search"><Icon name="search" /><input v-model="replySearch" placeholder="Search responses or shortcuts…" aria-label="Search canned responses" /></label><button v-for="reply in canned" :key="reply.id" class="canned-picker-item" @click="insertReply(reply)"><div><strong>{{ reply.title }}</strong><span class="shortcut-badges"><kbd v-for="shortcut in cannedShortcuts(reply.shortcut)" :key="shortcut">{{ shortcut }}</kbd></span></div><p class="canned-body-preview" v-html="replyEditorHtml(reply.body)" /></button><p v-if="!canned.length" class="muted">No matching responses. Create one in Canned responses.</p></Modal>
<Modal v-if="recording" title="Screen recording" @close="recording = false"><div class="recording-preview"><div class="recording-window"><div><i /><i /><i /></div><Icon name="video" :size="42" /><span>Your screen preview</span></div><span class="preview-badge">DESIGN PREVIEW</span><h3>A little context goes a long way.</h3><p>This is a preview of the recording interface. Screen capture, microphone access, and uploading are disabled.</p><button class="primary-button" @click="recording = false">Got it</button></div></Modal>
<Modal v-if="editing" title="Edit ticket information" wide @close="editing = false"><form class="form-stack" @submit.prevent="saveDetails"><label>Subject<input v-model="editForm.subject" required /></label><div class="form-grid"><label>Requester name<input v-model="editForm.requester_name" /></label><label>Email<input v-model="editForm.requester_email" type="email" required /></label></div><label>Company<input v-model="editForm.company" /></label><label>People in the loop<input v-model="editForm.cc" placeholder="Comma-separated email addresses" /></label><div class="form-actions"><button class="primary-button">Save changes</button></div></form></Modal>
<Modal v-if="linkDialog" title="Insert link" @close="linkDialog = false"><form class="form-stack" @submit.prevent="insertLink"><label>Text to display<input v-model="linkLabel" placeholder="Link text" /></label><label>Link address<input v-model="linkAddress" type="text" inputmode="url" placeholder="https://example.com" required /></label><p v-if="linkError" class="error-message" role="alert">{{ linkError }}</p><div class="form-actions"><button type="button" class="secondary-button" @click="linkDialog = false">Cancel</button><button type="submit" class="primary-button">Insert link</button></div></form></Modal>
</template>

<style>
.merged-ticket-links{display:grid;gap:10px;margin:24px 0}.merged-ticket-links>a{display:flex;align-items:center;gap:10px;padding:10px 0;color:var(--accent-ink);font-size:13px}.merged-ticket-links>a>span[dir]{overflow-wrap:anywhere}.merged-ticket-links small{color:var(--muted)}.merge-indicator{display:inline-flex;vertical-align:middle;margin-inline-start:6px;color:var(--text)}
.ticket-translation-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12px}.translation-switch{width:36px;height:22px;padding:3px;border:0;border-radius:20px;background:var(--muted);flex-shrink:0;cursor:pointer}.translation-switch[aria-checked="true"]{background:var(--accent-ink)}.translation-switch>span{display:block;width:16px;height:16px;border-radius:50%;background:#fff}.translation-switch[aria-checked="true"]>span{transform:translateX(14px)}.translation-switch:focus-visible{outline:2px solid var(--accent-ink);outline-offset:3px}.translation-switch:disabled{opacity:.5;cursor:default}
.conversation-event{display:flex;align-items:baseline;gap:9px;margin:24px 0 18px;color:var(--muted);font-size:11px;line-height:1.6}
.conversation-event>svg{align-self:center;flex-shrink:0}.conversation-event p{flex:1;margin:0;overflow-wrap:anywhere}.conversation-event a{color:var(--accent-ink)}.conversation-event strong{color:var(--text);font-weight:500}.conversation-event time{white-space:nowrap;font-size:10px}
.conversation-activity{margin:10px 0;font-size:12px}.conversation-activity time{font-size:11px}.conversation-activity+.message-card{margin-top:24px}
.requester-tickets-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;font-size:12px}.requester-ticket-row{display:flex;align-items:baseline;gap:10px;margin:10px 0;font-size:12px;line-height:1.6}.requester-ticket-row .status-badge{flex-shrink:0}.requester-ticket-row>a{color:var(--accent-ink);overflow-wrap:anywhere}.requester-archive{margin-top:24px;font-size:12px;line-height:1.8}.requester-archive strong{display:block;margin-bottom:8px}.requester-archive button{display:inline;font-size:inherit}.requester-archive p{color:var(--muted)}
.inspector-translation .inspector-section-content{display:grid;gap:12px}.inspector-section-content>.ticket-custom-fields{padding:0;border:0}
@media(max-width:640px){.conversation-event{flex-wrap:wrap}.conversation-event time{flex-basis:100%;margin-inline-start:23px}}
.message-card.outbound>header{background:var(--automation-header);color:var(--automation-ink);border-bottom:0}
.message-card.outbound>header .message-author small,.message-card.outbound>header time{color:inherit;opacity:.85}
.message-card.outbound>header .avatar{background:#ffffff1a;color:inherit}
.message-card:not(.outbound):not(.note):not(.automated)>header{background:var(--line)}
.inspector-fields{border:0;padding:0;margin:0;min-width:0}
.message-reply-addresses small{flex-wrap:wrap}.message-reply-addresses svg{flex-shrink:0}.message-reply-addresses span{overflow-wrap:anywhere;min-width:0}
.inspector-tools .ticket-tools{display:grid;gap:13px;padding:0}.inspector-tools .ticket-tools button{justify-content:flex-start;font-size:11px}
.inspector-tools .similar-ticket-notice{margin:14px 0 0;padding:10px;font-size:11px;width:100%;text-align:start;line-height:1.5}
.inspector-translation{display:grid;gap:12px;font-size:11px}.inspector-translation h3{margin:0}.reading-language{display:flex;align-items:center;gap:6px;color:var(--muted)}.inspector-translation>.text-button{justify-self:start;font-size:11px}.inspector-translation .error-message{font-size:11px;line-height:1.6}
</style>
