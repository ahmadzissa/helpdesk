<script setup>
import { ref, reactive, computed, watch, inject, onMounted, onBeforeUnmount } from 'vue';
import { useNavigation } from '../useNavigation';
import { state, api, notify, initials, relativeTime, statusClass } from '../store';
import Modal from '../components/Modal.vue';
import TicketRowMenu from '../components/TicketRowMenu.vue';
import { useBulkTicketDeletion } from '../useBulkTicketDeletion';
import { useTicketSearch } from '../useTicketSearch';
const route = useNavigation(), newTicket = inject('newTicket');
const tickets = ref([]), total = ref(0), loading = ref(true), loadError = ref(''), refreshError = ref(''), sort = ref('newest');
const selected = ref([]), page = ref(1), lastPage = ref(1), busy = ref(false), fetching = ref(false);
const { deleting, error: deleteError, open: confirmBulkDelete, confirm: deleteSelected } = useBulkTicketDeletion(selected, busy, async result => {
    notify(`${result.deleted} ticket${result.deleted === 1 ? '' : 's'} permanently deleted`);
    selected.value = []; await load(false, true);
});
const changes = reactive({ status: '', priority: '', assignee_id: '', team_id: '', folder: '', tag: '' });
const view = computed(() => route.query.view || 'all');
const { search, label: searchLabel } = useTicketSearch(view);
const title = computed(() => ({ all: 'All tickets', mine: 'Assigned to me', unassigned: 'Unassigned', unread: 'Unread', undelivered: 'Undelivered', archive: 'Archive', spam: 'Spam', trash: 'Trash' }[view.value] || state.workspace.views.find(v => 'saved:' + v.id === view.value)?.name || view.value));
const allSelected = computed(() => tickets.value.length > 0 && selected.value.length === tickets.value.length);
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);
let timer, polling, requestId = 0;
onMounted(() => { polling = setInterval(() => { if (view.value !== 'archive' && !document.hidden && !selected.value.length && !busy.value && !fetching.value) load(false, true); }, 30000); });
onBeforeUnmount(() => { clearTimeout(timer); clearInterval(polling); requestId++; });
async function load(append = false, quiet = false, includeCounts = true) {
    const id = ++requestId;
    const requestedPage = append ? page.value + 1 : quiet ? page.value : 1;
    if (!append && !quiet) { selected.value = []; loading.value = true; }
    fetching.value = true;
    loadError.value = ''; refreshError.value = '';
    const params = new URLSearchParams({ view: view.value, search: search.value, sort: sort.value, page: String(append ? requestedPage : 1), include_counts: includeCounts && !append ? '1' : '0' });
    if (state.scope !== 'all') { const [key, value] = state.scope.split(':'); params.set(key + '_id', value); }
    try {
        const data = await api('tickets?' + params);
        if (id !== requestId) return;
        const nextPage = Math.min(requestedPage, data.last_page);
        const refreshedTickets = [...data.tickets];
        if (!append && quiet) {
            for (let currentPage = 2; currentPage <= nextPage; currentPage++) {
                params.set('page', String(currentPage)); params.set('include_counts', '0');
                const next = await api('tickets?' + params);
                if (id !== requestId) return;
                refreshedTickets.push(...next.tickets);
            }
        }
        tickets.value = [...new Map((append ? [...tickets.value, ...refreshedTickets] : refreshedTickets).map(ticket => [ticket.id, ticket])).values()];
        page.value = nextPage;
        selected.value = selected.value.filter(ticketId => tickets.value.some(ticket => ticket.id === ticketId));
        total.value = data.total; lastPage.value = data.last_page;
        if (data.counts) { state.counts = data.counts; state.viewCounts = data.views; }
    } catch (e) { if (id === requestId) { if (loading.value) loadError.value = e.message; else refreshError.value = e.message; } }
    finally { if (id === requestId) { loading.value = false; fetching.value = false; } }
}
watch([view, () => state.scope, sort, () => state.refresh, search], (values, previous) => {
    clearTimeout(timer);
    if (values.slice(0, 3).some((value, index) => value !== previous?.[index])) load();
    else if (values[4] !== previous?.[4]) { requestId++; timer = setTimeout(() => load(false, false, false), 250); }
    else load(false, true);
}, { immediate: true });
function toggleAll() { selected.value = allSelected.value ? [] : tickets.value.map(t => t.id); }
async function bulk() {
    const payload = Object.fromEntries(Object.entries(changes).filter(([key, value]) => key !== 'tag' && value !== ''));
    if (payload.assignee_id === 'unassigned') payload.assignee_id = null;
    if (!Object.keys(payload).length && !changes.tag.trim()) { notify('Choose a change to apply.', true); return; }
    busy.value = true;
    try { await api('tickets/bulk', { method: 'POST', body: { ids: selected.value, changes: payload, tag: changes.tag.trim() || null } }); notify('Selected tickets updated'); Object.keys(changes).forEach(k => changes[k] = ''); selected.value = []; await load(false, true); }
    catch (e) { notify(e.message, true); } finally { busy.value = false; }
}
async function action(ticket, data) {
    if (busy.value) return;
    busy.value = true; requestId++;
    try { await api('tickets/' + ticket.id, { method: 'PATCH', body: data }); notify('Ticket updated'); await load(false, true); }
    catch (e) { notify(e.message, true); }
    finally { busy.value = false; fetching.value = false; }
}
function delivery(ticket) {
    const message = ticket.latest_message;
    if (message?.kind !== 'outbound') return { label: message?.kind === 'note' ? 'Private note' : 'Customer reply', icon: message?.kind === 'note' ? 'lock' : 'mail' };
    if (message.opened_at && ['sent', 'delivered'].includes(message.delivery)) return { label: 'Image opened', icon: 'checks' };
    return { label: { saved: 'Saved reply', sent: 'Sent', delivered: 'Delivered', suppressed: 'Suppressed', translation_pending: 'Needs translation', failed: 'Undelivered', queued: 'Queued', sending: 'Sending', held: 'Held for review' }[message.delivery] || 'Reply', icon: ['failed', 'held', 'suppressed', 'translation_pending'].includes(message.delivery) ? 'alert' : 'checks' };
}
</script>
<template>
<main class="inbox-home surface">
    <header class="page-heading">
        <button class="icon-button mobile-only" @click="state.mobileNav = true" aria-label="Open navigation"><Icon name="menu" /></button>
        <div class="heading-line"><h1>{{ title }}</h1><span class="heading-count">{{ total }}</span></div>
        <label class="ticket-main-search"><Icon name="search" /><input v-model="search" type="search" :placeholder="searchLabel + '…'" :aria-label="searchLabel" data-ticket-search /><kbd>/</kbd></label>
        <button class="primary-button" @click="newTicket"><Icon name="plus" />New ticket</button>
    </header>
    <p v-if="['archive', 'spam', 'trash'].includes(view)" class="folder-notice">
      <Icon :name="view" />{{ total }} {{ total === 1 ? 'conversation' : 'conversations' }} in {{ view }}.
      <template v-if="view === 'trash' || view === 'spam'">Tickets are permanently deleted after {{ view === 'trash' ? '7 days in Trash' : '1 month (30 days) in Spam' }}. Move a ticket to Inbox before then to keep it.</template>
      <template v-else>Search archived conversations from any date here. Move a ticket to Inbox to restore it.</template>
    </p>
    <p v-else class="folder-notice"><Icon name="clock" />Tickets active in the last 2 months (60 days). Older conversations are kept in Archive.</p>
    <form v-if="selected.length" class="bulk-bar" @submit.prevent="bulk">
        <strong>{{ selected.length }} selected</strong>
        <select v-model="changes.status" aria-label="Bulk status"><option value="">Set status</option><option v-for="s in state.workspace.statuses" :key="s">{{ s }}</option></select>
        <select v-model="changes.priority" aria-label="Bulk priority"><option value="">Priority</option><option v-for="p in state.workspace.priorities" :key="p">{{ p }}</option></select>
        <select v-model="changes.assignee_id" aria-label="Bulk assignee"><option value="">Assign agent</option><option value="unassigned">Unassigned</option><option v-for="agent in state.workspace.agents" :value="agent.id" :key="agent.id">{{ agent.name }}</option></select>
        <select v-model="changes.team_id" aria-label="Bulk team"><option value="">Assign team</option><option v-for="team in state.workspace.teams" :value="team.id" :key="team.id">{{ team.name }}</option></select>
        <input v-model="changes.tag" placeholder="Add tag…" aria-label="Bulk tag" maxlength="60" />
        <select v-model="changes.folder" aria-label="Bulk folder"><option value="">Move to…</option><option v-for="folder in ['inbox', 'archive', 'spam', 'trash']" :key="folder" :value="folder">{{ folder.charAt(0).toUpperCase() + folder.slice(1) }}</option></select>
        <button class="primary-button" :disabled="busy">Apply</button>
        <button v-if="state.user.role === 'admin'" type="button" class="danger-button" :disabled="busy" @click="confirmBulkDelete"><Icon name="trash" :size="15" />Delete permanently</button>
        <button type="button" @click="selected = []" :disabled="busy">Cancel</button>
    </form>
    <p v-if="refreshError" class="error-message" role="alert">Couldn’t refresh your tickets. {{ refreshError }} <button type="button" class="text-button" @click="load(false, true)">Try again</button></p>
    <div class="ticket-table" role="table" aria-label="Tickets">
        <div class="table-head" role="row"><span role="columnheader" class="select-cell"><input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected" @change="toggleAll" :disabled="!tickets.length" aria-label="Select all tickets" /><span class="mobile-select-text">Select all</span></span><span role="columnheader">Customer</span><span role="columnheader">Conversation</span><span role="columnheader">Status</span><span role="columnheader" class="assignee-cell">Assignee</span><span role="columnheader" class="delivery-cell">Email activity</span><span role="columnheader">Received</span><span role="columnheader"><button class="icon-button" @click="sort = sort === 'newest' ? 'oldest' : 'newest'" :aria-label="sort === 'newest' ? 'Sort oldest first' : 'Sort newest first'" :title="sort === 'newest' ? 'Newest first' : 'Oldest first'"><Icon name="sort" :size="16" /></button></span></div>
        <div v-if="loadError" class="empty-state"><Icon name="alert" :size="30" /><h2>Couldn’t load your tickets</h2><p>{{ loadError }}</p><button class="secondary-button" @click="load()">Try again</button></div>
        <div v-else-if="loading" class="skeleton-list"><div v-for="n in 7" :key="n" class="skeleton-row"><i /><div /><span /></div></div>
        <template v-else>
            <div v-for="ticket in tickets" :key="ticket.id" class="ticket-row" :class="{ unread: ticket.unread, selected: selected.includes(ticket.id) }" role="row">
                <span class="select-cell" role="cell"><input type="checkbox" v-model="selected" :value="ticket.id" :aria-label="'Select ticket ' + ticket.id" /><i v-if="ticket.unread" class="unread-dot" /></span>
                <span class="customer-cell" role="cell"><span class="avatar" :class="'avatar-' + ticket.id % 5">{{ initials(ticket.requester_name || ticket.requester_email) }}</span><span class="row-person"><strong :title="ticket.requester_email">{{ ticket.requester_email }}</strong></span></span>
                <span class="conversation-cell" role="cell"><Link :href="$appUrl('/tickets/' + ticket.id)" :title="ticket.subject">{{ ticket.subject }}</Link><span class="ticket-tags"><span class="ticket-id">#{{ ticket.id }}</span><span v-if="state.user.preferences?.source_indicators !== false && ticket.mailbox" class="source-label"><i :style="{ background: ticket.mailbox.color }" />{{ ticket.mailbox.name }}</span><span v-for="tag in ticket.tags?.slice(0, 2)" class="tag" :key="tag">{{ tag }}</span><span v-if="ticket.tags?.length > 2" class="tag">+{{ ticket.tags.length - 2 }}</span></span></span>
                <span role="cell" class="status-cell"><span class="status-badge" :class="statusClass(ticket.status)"><Icon :name="['Solved', 'Closed'].includes(ticket.status) ? 'solved' : ['Pending', 'On hold'].includes(ticket.status) ? 'clock' : 'circle'" :size="13" />{{ ticket.status }}</span></span>
                <span class="assignee-cell" role="cell"><span v-if="ticket.assignee" class="mini-avatar">{{ initials(ticket.assignee.name) }}</span><Icon v-else name="users" :size="15" />{{ ticket.assignee?.name.split(' ')[0] || 'Unassigned' }}</span>
                <span class="delivery-cell" :class="{ danger: ticket.latest_message?.delivery === 'failed' }" role="cell"><Icon :name="delivery(ticket).icon" :size="15" />{{ delivery(ticket).label }}</span>
                <span class="time-cell" role="cell" :title="new Date(ticket.last_activity_at).toLocaleString()">{{ relativeTime(ticket.last_activity_at) }}</span>
                <span role="cell" class="actions-cell"><TicketRowMenu :ticket="ticket" :disabled="busy" @action="action(ticket, $event)" /></span>
            </div>
            <div v-if="!tickets.length" class="empty-state"><div class="empty-icon"><Icon :name="search ? 'search' : 'inbox'" :size="30" /></div><h2>{{ search ? 'No matching conversations' : 'A little breathing room.' }}</h2><p>{{ search ? 'Try a different subject, requester, ticket ID, or tag.' : 'There are no tickets in this view. New conversations will appear here.' }}</p><button v-if="search" class="secondary-button" @click="search = ''">Clear search</button><button v-else class="secondary-button" @click="newTicket"><Icon name="plus" />Create a ticket</button></div>
        </template>
    </div>
    <button v-if="page < lastPage && !loading" class="load-more secondary-button" :disabled="fetching" @click="load(true)">Load more conversations</button>
</main>
<Modal v-if="deleting" title="Permanently delete selected tickets?" @close="!busy && (deleting = null)">
    <p class="delete-description">Permanently delete <strong>{{ deleting.length }} selected {{ deleting.length === 1 ? 'ticket' : 'tickets' }}</strong> and any conversations merged into them?</p>
    <p class="error-message">Their messages, drafts and uploaded files will be removed. This cannot be undone.</p>
    <p class="form-description">Emails at your provider are untouched. Previously imported messages will not be imported again.</p>
    <p v-if="deleteError" class="error-message" role="alert">{{ deleteError }}</p>
    <div class="form-actions"><button type="button" class="secondary-button" :disabled="busy" @click="deleting = null">Cancel</button><button type="button" class="danger-button" :disabled="busy" @click="deleteSelected">{{ busy ? 'Deleting…' : 'Delete permanently' }}</button></div>
</Modal>
</template>
