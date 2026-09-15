<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { api, statusClass } from '../store';
import Modal from './Modal.vue';
const props = defineProps({ ticket: { type: Object, required: true } });
const emit = defineEmits(['close', 'merged']);
const step = ref(1), search = ref(''), page = ref(1), results = ref(null), selected = ref([]);
const loading = ref(false), busy = ref(false), error = ref('');
const selectedIds = computed(() => selected.value.map(ticket => ticket.id));
let request = 0;
const when = date => date ? new Date(date).toLocaleString() : '';
async function load() {
    const current = ++request;
    loading.value = true; error.value = '';
    try {
        const params = new URLSearchParams({ merge_candidates: '1', page: String(page.value), q: search.value.trim() });
        const data = await api('tickets/' + props.ticket.id + '/history?' + params);
        if (current === request) results.value = data.history;
    } catch (e) { if (current === request) error.value = e.message; }
    finally { if (current === request) loading.value = false; }
}
function select(ticket, checked) {
    if (checked && selected.value.length < 20) selected.value.push(ticket);
    else if (!checked) selected.value = selected.value.filter(item => item.id !== ticket.id);
}
async function merge() {
    if (busy.value || !selected.value.length) return;
    busy.value = true; error.value = '';
    try {
        await api('tickets/' + props.ticket.id + '/merge', { method: 'POST', body: { ticket_ids: selectedIds.value } });
        emit('merged');
    } catch (e) { error.value = e.message; }
    finally { busy.value = false; }
}
onMounted(load);
onBeforeUnmount(() => { request++; });
</script>
<template>
<Modal title="Merge requester's tickets" wide @close="!busy && emit('close')">
    <div class="merge-tickets">
        <div class="merge-explanation"><Icon name="alert" :size="22" /><p>Merging cannot be undone. Selected tickets will be closed and become read only. Their existing messages stay in those tickets. Future customer replies go to the main ticket. Tags are combined.</p></div>
        <h3>Main ticket</h3>
        <div class="merge-ticket-row"><span class="status-badge" :class="statusClass(ticket.status)">{{ ticket.status }}</span><Link :href="$appUrl('/tickets/' + ticket.id)" dir="auto">{{ ticket.subject }}</Link><time :datetime="ticket.last_activity_at">{{ when(ticket.last_activity_at) }}</time></div>
        <template v-if="step === 1">
            <h3>Select tickets to merge into the main ticket</h3>
            <form class="merge-search" @submit.prevent="page = 1; load()"><input v-model="search" type="search" maxlength="200" placeholder="Search this requester’s tickets…" aria-label="Search tickets to merge" /><button type="submit" class="secondary-button" :disabled="loading">Search</button></form>
            <p v-if="loading" class="muted" role="status">Loading tickets…</p>
            <template v-else-if="results && !error">
                <label v-for="item in results.data" :key="item.id" class="merge-ticket-row merge-select-row"><input type="checkbox" :checked="selectedIds.includes(item.id)" :disabled="selected.length >= 20 && !selectedIds.includes(item.id)" :aria-label="'Select ticket: ' + item.subject" @change="select(item, $event.target.checked)" /><span class="status-badge" :class="statusClass(item.status)">{{ item.status }}</span><span class="merge-subject" dir="auto">{{ item.subject }}</span><time :datetime="item.last_activity_at">{{ when(item.last_activity_at) }}</time></label>
                <p v-if="!results.data.length" class="muted">No eligible tickets found. Tickets must belong to this requester and mailbox.</p>
                <div v-if="results.last_page > 1" class="merge-pagination"><button class="secondary-button" :disabled="page === 1" @click="page--; load()">Previous</button><span>{{ page }} / {{ results.last_page }}</span><button class="secondary-button" :disabled="page === results.last_page" @click="page++; load()">Next</button></div>
            </template>
            <p v-if="selected.length" class="muted">{{ selected.length }} selected · up to 20 tickets</p>
        </template>
        <template v-else>
            <h3>Close and merge {{ selected.length }} ticket{{ selected.length === 1 ? '' : 's' }}</h3>
            <div v-for="item in selected" :key="item.id" class="merge-ticket-row"><Icon name="merged" /><span class="merge-subject" dir="auto">{{ item.subject }}</span></div>
            <p class="muted">You will reply from the main ticket. Links to these tickets will appear below its conversation. Queued replies in the selected tickets are held, and scheduled follow-ups are cancelled.</p>
        </template>
        <p v-if="error" class="error-message" role="alert">{{ error }} <button v-if="step === 1" class="text-button" @click="load">Retry</button></p>
        <footer class="merge-footer"><span>Step {{ step }} of 2</span><div><button class="secondary-button" :disabled="busy" @click="step === 1 ? emit('close') : (step = 1)">{{ step === 1 ? 'Cancel' : 'Back' }}</button><button v-if="step === 1" class="primary-button" :disabled="!selected.length || loading || Boolean(error)" @click="step = 2">Continue</button><button v-else class="primary-button" :disabled="busy" @click="merge">{{ busy ? 'Merging…' : 'Merge tickets' }}</button></div></footer>
    </div>
</Modal>
</template>
<style scoped>
.merge-tickets{display:grid;gap:16px}.merge-tickets h3{font-size:14px;margin:4px 0 0}.merge-explanation{display:flex;align-items:flex-start;gap:14px;padding:18px;border-radius:10px;background:color-mix(in srgb,var(--accent-ink) 12%,var(--bg));font-size:13px;line-height:1.7}.merge-explanation svg{flex-shrink:0;color:var(--accent-ink);margin-top:3px}.merge-explanation p{margin:0}.merge-ticket-row{display:flex;align-items:center;gap:10px;padding:14px;border:1px solid var(--line);border-radius:10px;font-size:12px;min-width:0}.merge-ticket-row>a,.merge-subject{color:var(--accent-ink);overflow-wrap:anywhere;flex:1;min-width:0}.merge-ticket-row .status-badge,.merge-ticket-row input{flex-shrink:0}.merge-ticket-row time{margin-inline-start:auto;white-space:nowrap;color:var(--muted);font-size:11px}.merge-select-row{cursor:pointer}.merge-search{display:flex;gap:10px}.merge-search input{flex:1;min-width:0}.merge-pagination,.merge-footer,.merge-footer>div{display:flex;align-items:center;gap:12px}.merge-footer{justify-content:space-between;margin-top:8px}.merge-footer>span{font-size:12px;color:var(--muted)}.merge-tickets>.muted{font-size:12px;line-height:1.7}@media(max-width:640px){.merge-ticket-row{flex-wrap:wrap}.merge-ticket-row time{flex-basis:100%;margin-inline-start:0}.merge-footer{align-items:flex-start}}
</style>
