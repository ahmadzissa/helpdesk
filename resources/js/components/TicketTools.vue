<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { state, api, notify } from '../store';
import Modal from './Modal.vue';
import MergeTickets from './MergeTickets.vue';
const props = defineProps({ ticket: { type: Object, required: true } });
const emit = defineEmits(['refresh', 'history']);
const mode = ref(''), history = ref(null), page = ref(1), selected = ref([]), macros = ref([]), macro = ref(null), requestId = ref(''), follows = ref([]), busy = ref(false), error = ref('');
const follow = reactive({ body: '', due_at: '', cancel_on_reply: true, status_after: '' });
const archiveOnly = ref(false), historySearch = ref(''), historyLoading = ref(false);
let historyRequest = 0;
const pending = computed(() => follows.value.filter(f => f.state === 'pending'));
const when = date => date ? new Date(date.includes('T') || date.endsWith('Z') ? date : date + 'Z').toLocaleString() : '';
async function loadHistory() {
    const request = ++historyRequest;
    const params = new URLSearchParams({ page: String(page.value) });
    if (archiveOnly.value) params.set('folder', 'archive');
    if (historySearch.value.trim()) params.set('q', historySearch.value.trim());
    historyLoading.value = true;
    try {
        const result = await api('tickets/' + props.ticket.id + '/history?' + params);
        if (request !== historyRequest) return;
        history.value = result; emit('history', result);
    } catch (e) { if (request === historyRequest) { error.value = e.message; emit('history', { recent: [], error: e.message }); } }
    finally { if (request === historyRequest) historyLoading.value = false; }
}
async function openHistory(archived = false) {
    archiveOnly.value = archived; historySearch.value = ''; page.value = 1; selected.value = []; error.value = ''; history.value = null; mode.value = 'history'; await loadHistory();
}
async function searchHistory() { page.value = 1; selected.value = []; error.value = ''; await loadHistory(); }
function openMerge() { mode.value = 'merge'; }
async function merged() { mode.value = ''; await refresh(); emit('refresh'); notify('Tickets merged. Earlier messages stay in their original tickets.'); }
defineExpose({ openHistory, openMerge });
async function loadFollows() { follows.value = await api('tickets/' + props.ticket.id + '/follow-ups'); }
async function refresh() { try { await Promise.all([loadHistory(), loadFollows()]); } catch (e) { notify(e.message, true); } }
async function open(value) { mode.value = value; error.value = ''; if (value === 'macros') { try { macros.value = (await api('workflows')).macros.filter(m => m.enabled); macro.value = null; } catch (e) { error.value = e.message; } } if (value === 'history') selected.value = []; if (value === 'follow') await loadFollows().catch(e => error.value = e.message); }
function choose(item) { macro.value = item; requestId.value = crypto.randomUUID(); }
async function apply() { busy.value = true; error.value = ''; try { await api('tickets/' + props.ticket.id + '/macros/' + macro.value.id, { method: 'POST', body: { request_id: requestId.value } }); mode.value = ''; await refresh(); emit('refresh'); notify('Macro applied'); } catch (e) { error.value = e.message; } finally { busy.value = false; } }
async function schedule() { busy.value = true; error.value = ''; try { await api('tickets/' + props.ticket.id + '/follow-ups', { method: 'POST', body: { ...follow, status_after: follow.status_after || null, due_at: new Date(follow.due_at).toISOString() } }); follow.body = ''; follow.due_at = ''; await loadFollows(); notify('Follow-up scheduled'); } catch (e) { error.value = e.message; } finally { busy.value = false; } }
async function cancel(item) { try { await api('tickets/' + props.ticket.id + '/follow-ups/' + item.id, { method: 'DELETE' }); await loadFollows(); notify('Follow-up cancelled'); } catch (e) { error.value = e.message; } }
const actionLabel = action => ({ status: 'Set status', priority: 'Set priority', folder: 'Move to folder', assignee_id: 'Assign agent', team_id: 'Assign team', add_tag: 'Add tag', remove_tag: 'Remove tag', reply_id: 'Send canned response', note: 'Add private note', follow_up: 'Follow-up delay (minutes)', send_message: 'Send message', send_follow_up: 'Send follow-up' }[action.type]) + ': ' + (action.type === 'reply_id' ? state.workspace.replies.find(r => r.id === Number(action.value))?.title || action.value : action.value ?? 'Unassigned');
onMounted(refresh);
watch([() => props.ticket.id, () => props.ticket.requester_email, () => props.ticket.mailbox_id, () => props.ticket.messages?.length], refresh);
</script>
<template>
<div v-if="!ticket.merged_into_id" class="ticket-tools"><button class="text-button" @click="open('macros')"><Icon name="bolt" :size="14" />Apply macro</button><button class="text-button" @click="open('follow')"><Icon name="clock" :size="14" />Follow-ups{{ pending.length ? ' (' + pending.length + ')' : '' }}</button></div>
<Modal v-if="mode === 'history'" :title="archiveOnly ? 'Requester’s archived tickets' : 'Requester’s tickets'" wide @close="!busy && (mode = '')"><div class="form-stack"><p>{{ archiveOnly ? 'Archived conversations from' : 'All conversations from' }} <strong>{{ ticket.requester_email }}</strong>.</p><form class="history-search" @submit.prevent="searchHistory"><label>Search {{ archiveOnly ? 'archived tickets' : 'ticket subjects' }}<input v-model="historySearch" type="search" maxlength="200" placeholder="Search by subject…" /></label><button class="secondary-button" :disabled="historyLoading">Search</button></form><p v-if="error" class="error-message" role="alert">{{ error }} <button type="button" class="text-button" @click="loadHistory">Retry</button></p><p v-if="historyLoading" class="muted" role="status">Loading tickets…</p>
<template v-if="history && !historyLoading"><h3 v-if="!archiveOnly && !historySearch && history.open.length">Other open tickets</h3><div v-for="item in !archiveOnly && !historySearch ? history.open : []" :key="item.id" class="history-row"><div><Link :href="$appUrl('/tickets/' + item.id)">{{ item.subject }}</Link><small>{{ item.status }}{{ item.similar ? ' · Similar subject' : '' }}{{ !item.merge_allowed ? ' · Different mailbox' : '' }}</small></div></div>
<h3>{{ archiveOnly ? "Archived tickets" : "Previous conversations" }} ({{ history.history.total }})</h3><div v-for="item in history.history.data" :key="item.id" class="history-row"><div><Link :href="$appUrl('/tickets/' + item.id)">{{ item.subject }}</Link><small>{{ item.status }} · {{ item.folder }} · {{ when(item.created_at) }}{{ item.merged_into_id ? ' · Merged into another ticket' : '' }}</small></div></div><p v-if="!history.history.total" class="muted">{{ archiveOnly ? "No archived tickets match this search." : "No previous conversations match this search." }}</p>
<div v-if="history.history.last_page > 1" class="form-actions"><button class="secondary-button" :disabled="page === 1" @click="page--; loadHistory()">Previous</button><span>{{ page }} / {{ history.history.last_page }}</span><button class="secondary-button" :disabled="page === history.history.last_page" @click="page++; loadHistory()">Next</button></div></template>
</div></Modal>
<MergeTickets v-if="mode === 'merge'" :ticket="ticket" @close="mode = ''" @merged="merged" />
<Modal v-if="mode === 'macros'" title="Apply a macro" @close="!busy && (mode = '')"><p v-if="error" class="error-message">{{ error }}</p><template v-if="!macro"><button v-for="item in macros" :key="item.id" class="canned-picker-item" @click="choose(item)"><strong>{{ item.name }}</strong><p>{{ item.actions.length }} actions · Review before applying</p></button><p v-if="!macros.length" class="muted">Create a macro in Automations & macros first.</p></template><div v-else class="form-stack"><h3>{{ macro.name }}</h3><ol class="macro-preview"><li v-for="(action, i) in macro.actions" :key="i">{{ actionLabel(action) }}<p v-if="action.body" class="muted">{{ action.body }}</p></li></ol><p class="muted">{{ state.workspace.translation?.outgoing ? 'Reply actions wait for browser translation and review.' : 'Reply actions send immediately when sending is available.' }} The sending pause and recipient restrictions always apply.</p><div class="form-actions"><button class="secondary-button" @click="macro = null" :disabled="busy">Back</button><button class="primary-button" @click="apply" :disabled="busy">{{ busy ? 'Applying…' : 'Apply these actions' }}</button></div></div></Modal>
<Modal v-if="mode === 'follow'" title="Timed follow-ups" wide @close="!busy && (mode = '')"><form class="form-stack" @submit.prevent="schedule"><p v-if="error" class="error-message">{{ error }}</p><label>Follow-up message<textarea v-model="follow.body" rows="4" required maxlength="20000" placeholder="Hi {{name}}, just checking whether you still need help…" /></label><div class="form-grid"><label>Send at (your local time)<input v-model="follow.due_at" type="datetime-local" required /></label><label>Status after follow-up<select v-model="follow.status_after"><option value="">Keep current status</option><option v-for="status in state.workspace.statuses" :key="status">{{ status }}</option></select></label></div><label class="check-label"><input type="checkbox" v-model="follow.cancel_on_reply" />Cancel automatically if the customer replies</label><p class="muted">Checked every minute. A due reply is held for review when sending is paused. {{ state.workspace.translation?.outgoing ? 'Browser translation and review are required before delivery.' : '' }} Its saved message shows the final delivery result.</p><div><button class="primary-button" :disabled="busy">Schedule follow-up</button></div></form>
<section class="policy-section"><h3>Scheduled & recent follow-ups</h3><div v-for="item in follows" :key="item.id" class="management-row"><Icon name="clock" /><div class="management-row-main"><h3>{{ when(item.due_at) }}</h3><p>{{ item.body.slice(0, 120) }}</p><small>{{ item.state }}{{ item.result ? ' · ' + item.result : '' }}</small></div><button v-if="item.state === 'pending'" class="text-button" @click="cancel(item)">Cancel follow-up</button></div><p v-if="!follows.length" class="muted">No follow-ups scheduled.</p></section></Modal>
</template>

<style scoped>
.history-search{display:flex;align-items:end;gap:12px}.history-search label{flex:1;min-width:0}.history-search input{width:100%}
</style>
