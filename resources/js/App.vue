<script setup>
import { ref, reactive, computed, provide, watch, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useNavigation } from './useNavigation';
import { appUrl } from './urls';
import { state, syncPage, api, notify, initials, refreshSidebar, refreshSendingSafety } from './store';
import Modal from './components/Modal.vue';
import CreateTicketForm from './components/CreateTicketForm.vue';
import { createLocalMailPoller } from './localMailPolling';
const route = useNavigation(), inertiaPage = usePage();
watch(() => inertiaPage.props, props => syncPage(props), { immediate: true, flush: 'sync' });
const creating = ref(false), saving = ref(false), createError = ref('');
let safetyTimer, mailTimer;
const mailPoller = createLocalMailPoller({
    enabled: () => Boolean(state.user),
    visible: () => !document.hidden,
    request: () => api('mailboxes/poll', { method: 'POST' }),
    refreshed: () => { state.refresh++; },
    failed: message => notify(message, true),
});
function pollLocalMail() { mailPoller.poll(); }
watch(() => Boolean(state.user), pollLocalMail);
const emptyTicket = () => ({ subject: '', requester_name: '', requester_email: '', body: '', customer_language: null, priority: 'Normal', source: 'Email', mailbox_id: state.workspace.mailboxes.find(box => box.sending_enabled)?.id || null, team_id: state.workspace.mailboxes.find(box => box.sending_enabled)?.team_id || null });
const form = reactive(emptyTicket());
function newTicket() { Object.assign(form, emptyTicket()); creating.value = true; createError.value = ''; }
provide('newTicket', newTicket);
const page = computed(() => route.path.startsWith('/tickets') ? 'tickets' : route.params.page || route.path.split('/')[1] || 'tickets');
const isSettings = computed(() => page.value === 'settings');
const sections = [['general', 'General', 'settings'], ['translation', 'Translation', 'globe'], ['custom-fields', 'Custom fields', 'file'], ['mail-policy', 'Senders & delivery', 'spam'], ['api', 'API access', 'globe'], ['views', 'Ticket views', 'filters'], ['accounts', 'Email accounts', 'mail'], ['groups', 'Groups & agents', 'users'], ['themes', 'Appearance', 'circle'], ['inbox', 'Inbox preferences', 'inbox']];
const rails = [['tickets', 'Tickets', 'inbox'], ['replies', 'Canned responses', 'message'], ['automations', 'Automations', 'bolt'], ['reports', 'Reports', 'chart']];
const statuses = ['Open', 'Pending', 'On hold', 'Solved', 'Closed'];
const view = computed(() => route.query.view || 'all');
watch(() => state.user?.id, () => {
    if (state.user) refreshSidebar().catch(e => notify(e.message, true));
    else creating.value = false;
}, { immediate: true });
watch([() => state.scope, () => state.refresh], () => {
    if (state.user && route.path !== '/tickets') refreshSidebar().catch(e => notify(e.message, true));
});
function goView(v) { if (v === 'all') pollLocalMail(); router.visit(appUrl({ path: '/tickets', query: v === 'all' ? {} : { view: v } })); state.mobileNav = false; }
async function createTicket() {
    if (saving.value) return;
    saving.value = true;
    try {
        const result = await api('tickets', { method: 'POST', body: form });
        const message = result.data.messages[0];
        const needsTranslation = message?.delivery === 'translation_pending' && !message.rule_name;
        creating.value = false; state.refresh++;
        notify(needsTranslation ? 'Ticket created. Prepare the first reply for delivery.' : message?.delivery === 'queued' ? 'Ticket created. Message queued for delivery.' : message?.delivery === 'held' ? 'Ticket created. Message held because sending is paused.' : 'Ticket created. Message saved; connect a sending mailbox to deliver it.');
        router.visit(appUrl('/tickets/' + result.data.id) + (needsTranslation ? '?prepare_reply=' + message.id : ''));
    } catch (e) { createError.value = e.message; } finally { saving.value = false; }
}
function logout() { router.post(appUrl('/logout')); }
function shortcut(e) {
    if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.target.isContentEditable || e.ctrlKey || e.metaKey || !state.user) return;
    if (e.key.toLowerCase() === 'n') { e.preventDefault(); newTicket(); }
    if (e.key === '/') { e.preventDefault(); document.querySelector('[data-ticket-search]')?.focus(); }
}
watch(() => route.fullPath, () => { state.mobileNav = false; if (route.path === '/tickets' && view.value === 'all') pollLocalMail(); });
onMounted(() => {
    document.addEventListener('keydown', shortcut);
    safetyTimer = setInterval(() => refreshSendingSafety().catch(() => {}), 15000);
    pollLocalMail();
    mailTimer = setInterval(pollLocalMail, 30000);
    document.addEventListener('visibilitychange', pollLocalMail);
    window.addEventListener('focus', pollLocalMail);
    window.addEventListener('online', pollLocalMail);
});
onBeforeUnmount(() => { document.removeEventListener('keydown', shortcut); document.removeEventListener('visibilitychange', pollLocalMail); window.removeEventListener('focus', pollLocalMail); window.removeEventListener('online', pollLocalMail); clearInterval(safetyTimer); clearInterval(mailTimer); mailPoller.dispose(); });
</script>
<template>
<Head :title="'Relay — ' + (inertiaPage.component === 'Auth' ? 'Your support workspace' : page.charAt(0).toUpperCase() + page.slice(1))" />
<slot v-if="inertiaPage.component === 'Auth'" />
<div v-else class="app-shell">
    <nav class="app-rail" aria-label="Application navigation">
        <Link class="rail-brand brand-mark" :href="$appUrl('/tickets')" aria-label="Relay home">r<Icon name="arrow" :size="15" /></Link>
        <div class="rail-links"><Link v-for="[id, label, icon] in rails" :key="id" :href="$appUrl('/' + id)" class="rail-link" :class="{ active: page === id }" :aria-label="label" :title="label"><Icon :name="icon" :size="21" /><span class="rail-tooltip">{{ label }}</span></Link></div>
        <div class="rail-bottom"><Link class="rail-link" :class="{ active: page === 'settings' }" :href="$appUrl('/settings')" aria-label="Settings" title="Settings"><Icon name="settings" :size="21" /><span class="rail-tooltip">Settings</span></Link><Link :href="$appUrl('/profile')" class="rail-link profile-link" aria-label="Your profile" title="Your profile"><span class="rail-avatar">{{ initials(state.user.name) }}</span></Link></div>
    </nav>
    <div v-if="state.mobileNav" class="sidebar-backdrop" @click="state.mobileNav = false"></div>
    <aside class="sidebar" :class="{ visible: state.mobileNav }" aria-label="Workspace navigation">
        <div class="sidebar-heading"><h2>{{ isSettings ? 'Settings' : 'Tickets' }}</h2><button class="icon-button mobile-only" @click="state.mobileNav = false" aria-label="Close navigation"><Icon name="x" /></button></div>
        <template v-if="isSettings">
            <div class="sidebar-scroll"><p class="sidebar-caption">WORKSPACE</p><Link v-for="[id, label, icon] in sections" :key="id" :href="$appUrl({ path: '/settings', query: { section: id } })" class="nav-item" :class="{ active: (route.query.section || 'general') === id }"><Icon :name="icon" /><span>{{ label }}</span></Link><div class="sidebar-note"><Icon name="lock" /><p>{{ state.user.role === 'admin' ? 'Your workspace, your way.' : 'Workspace settings are managed by your administrator.' }}</p></div></div>
        </template>
        <template v-else>
            <label class="mailbox-switcher"><span>INBOX</span><select v-model="state.scope" aria-label="Switch account or group"><option value="all">All email accounts</option><optgroup label="Email accounts"><option v-for="box in state.workspace.mailboxes" :key="box.id" :value="'mailbox:' + box.id">{{ box.name }}</option></optgroup><optgroup label="Groups"><option v-for="team in state.workspace.teams" :key="team.id" :value="'team:' + team.id">{{ team.name }}</option></optgroup></select></label>
            <div class="sidebar-scroll">
                <nav class="main-nav"><button v-for="[id, label, icon] in [['all', 'All tickets', 'inbox'], ['mine', 'Assigned to me', 'user'], ['unassigned', 'Unassigned', 'users'], ['unread', 'Unread', 'mail'], ['undelivered', 'Undelivered', 'alert']]" :key="id" class="nav-item" :class="{ active: page === 'tickets' && view === id }" @click="goView(id)"><Icon :name="icon" /><span>{{ label }}</span><b v-if="state.counts[id] !== undefined">{{ state.counts[id] }}</b></button></nav>
                <div class="nav-label"><span>TICKET VIEWS</span><Link :href="$appUrl('/settings?section=views')">Manage</Link></div>
                <nav class="main-nav"><button v-for="saved in state.workspace.views" :key="saved.id" class="nav-item" :class="{ active: view === 'saved:' + saved.id }" @click="goView('saved:' + saved.id)"><Icon name="tag" /><span>{{ saved.name }}</span><b v-if="state.viewCounts.find(v => v.id === saved.id)">{{ state.viewCounts.find(v => v.id === saved.id).count }}</b></button><span v-if="!state.workspace.views.length" class="nav-empty">Create your first ticket view</span></nav>
                <div class="nav-label">STATUSES</div>
                <nav class="main-nav"><button v-for="status in statuses" :key="status" class="nav-item" :class="{ active: page === 'tickets' && view === status }" @click="goView(status)"><span class="status-dot" :class="status.toLowerCase().replaceAll(' ', '-')" /><span>{{ status }}</span><b>{{ state.counts[status] ?? 0 }}</b></button></nav>
                <div class="nav-label">FOLDERS</div>
                <nav class="main-nav"><button v-for="folder in ['archive', 'spam', 'trash']" :key="folder" class="nav-item" :class="{ active: view === folder }" @click="goView(folder)"><Icon :name="folder" /><span>{{ folder.charAt(0).toUpperCase() + folder.slice(1) }}</span></button></nav>
            </div>
        </template>
        <div class="workspace-footer"><span class="workspace-logo">{{ (state.workspace.settings.general?.name || 'Relay').charAt(0) }}</span><div><strong>{{ state.workspace.settings.general?.name || 'Relay' }}</strong><small>Support workspace</small></div><button class="icon-button" @click="logout" aria-label="Sign out" title="Sign out"><Icon name="logout" :size="16" /></button></div>
    </aside>
    <div class="main-shell"><div v-if="state.workspace.sending_safety?.paused" class="sending-pause-banner" role="status"><Icon name="alert" :size="19" /><div><strong>Receive-only mode</strong><span>Outgoing email is paused until administrator review. Incoming mail continues.</span></div><Link :href="$appUrl('/settings?section=accounts')">Review sending</Link></div><slot /></div>
</div>
<Teleport to="body"><div v-if="state.toast" class="toast" :class="{ error: state.toastError }" role="status"><Icon :name="state.toastError ? 'alert' : 'solved'" /><span>{{ state.toast }}</span><button @click="state.toast = ''" aria-label="Dismiss notification"><Icon name="x" :size="16" /></button></div></Teleport>
<Modal v-if="creating" title="New ticket" wide panel-class="ticket-create-modal" @close="!saving && (creating = false)">
    <CreateTicketForm :form="form" :workspace="state.workspace" :saving="saving" :error="createError" @submit="createTicket" @cancel="creating = false" />
</Modal>
</template>
