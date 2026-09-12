<script setup>
import { computed, ref, reactive, watch } from 'vue';
import { useNavigation } from '../useNavigation';
import { state, api, bootstrap, notify, palettes, applyTheme, initials, relativeTime } from '../store';
import Modal from '../components/Modal.vue';
import SendingSafety from '../components/SendingSafety.vue';
import MailPolicySettings from '../components/MailPolicySettings.vue';
import ApiSettings from '../components/ApiSettings.vue';
import TranslationSettings from '../components/TranslationSettings.vue';
import CustomFieldSettings from '../components/CustomFieldSettings.vue';
import { useMailboxConnections } from '../useMailboxConnections';
const route = useNavigation();
const page = computed(() => route.params.page || 'settings'), section = computed(() => route.query.section || 'general');
const admin = computed(() => state.user.role === 'admin');
const search = ref(''), modal = ref(null), saving = ref(false), formError = ref(''), deleting = ref(null), form = reactive({});
const { testing: testingConnections, results: connectionResults, token: connectionToken, error: connectionError, required: connectionTestRequired, passed: connectionsPassed, canSave: canSaveAccount, test: testConnections } = useMailboxConnections(form, modal);
const days = ref(30), reports = ref(null), reportError = ref(''), activities = ref([]), activityPage = ref(1), activityLastPage = ref(1);
const general = reactive({ name: state.workspace.settings.general?.name || 'Relay', timezone: state.workspace.settings.general?.timezone || 'Asia/Riyadh', registration_enabled: state.workspace.registration_enabled === true, show_email_images: state.workspace.settings.general?.show_email_images !== false });
const profile = reactive({ name: state.user.name, email: state.user.email, current_password: '', password: '', password_confirmation: '' });
const preferences = reactive({ theme: 'orchid', colors: { accent: '#7450bb', rail: '#272331', background: '#f8f6fc', surface: '#ffffff' }, initial_view: 'all', source_indicators: true, signature: '', ...state.user.preferences });
const title = computed(() => page.value === 'settings' ? ({ general: 'General settings', 'custom-fields': 'Custom fields', translation: 'Email translation', 'mail-policy': 'Senders & delivery', api: 'API access', views: 'Ticket views', accounts: 'Email accounts', groups: 'Groups & agents', themes: 'Appearance', inbox: 'Inbox preferences' }[section.value] || 'General settings') : ({ replies: 'Canned responses', reports: 'Reports', profile: 'Your profile', activity: 'Activity log' }[page.value]));
const descriptions = { 'Ticket views': 'Your inbox, organized around the work that matters.', 'Email accounts': 'Every address. One place for the conversation.', 'Groups & agents': 'Good support is a team effort.', Appearance: 'A workspace that feels like yours.', 'Inbox preferences': 'Set the starting point for your day.', 'General settings': 'A few details that make this workspace your own.', 'Canned responses': 'The right words, ready when you need them.', Reports: 'A clearer picture of your team’s support.', 'Your profile': 'How you show up in the conversation.', 'Activity log': 'A shared history of your workspace.' };
const filteredReplies = computed(() => state.workspace.replies.filter(r => (r.title + r.shortcut + r.body + r.category).toLowerCase().includes(search.value.toLowerCase())));
descriptions['Custom fields'] = 'Define the extra details available on every ticket.';
const fields = [['status', 'Status', () => state.workspace.statuses.map(s => [s, s])], ['priority', 'Priority', () => state.workspace.priorities.map(s => [s, s])], ['assignee_id', 'Agent', () => [['unassigned', 'Unassigned'], ...state.workspace.agents.map(a => [a.id, a.name])]], ['team_id', 'Team', () => state.workspace.teams.map(t => [t.id, t.name])], ['source', 'Source', () => ['Email', 'Web', 'Webhook', 'Phone'].map(s => [s, s])]];
function label(key, value) { return fields.find(f => f[0] === key)?.[2]().find(([v]) => String(v) === String(value))?.[1] || value; }
function clean(object = {}) { return Object.fromEntries(Object.entries(object).filter(([, v]) => v !== '' && v !== null)); }
function open(type, record = null) {
    Object.keys(form).forEach(k => delete form[k]);
    const defaults = {
        views: { name: '', tag: '', filters: {} }, replies: { title: '', shortcut: '#', category: 'General', body: '' }, teams: { name: '', description: '' },
        agents: { name: '', email: '', role: 'agent', password: '' },
        mailboxes: { name: '', email: '', team_id: null, color: '#7450bb', smtp_host: '', smtp_port: 587, smtp_encryption: 'tls', smtp_username: '', smtp_password: '', imap_host: '', imap_port: 993, imap_username: '', imap_password: '', sending_enabled: false, incoming_enabled: false, imap_encryption: 'ssl' },
    };
    Object.assign(form, structuredClone(defaults[type]), record ? JSON.parse(JSON.stringify(record)) : {});
    if (type === 'views') form.filters = { ...Object.fromEntries(fields.map(f => [f[0], ''])), ...form.filters };
    modal.value = { type, id: record?.id, title: (record ? 'Edit ' : 'New ') + ({ views: 'ticket view', replies: 'canned response', teams: 'group', agents: 'agent', mailboxes: 'email account' }[type]) };
    formError.value = '';
}
async function save() {
    if (modal.value.type === 'mailboxes' && !canSaveAccount.value) {
        formError.value = 'Test both SMTP and IMAP successfully before saving this account.';
        return;
    }
    saving.value = true; formError.value = '';
    try {
        const data = JSON.parse(JSON.stringify(form));
        if (modal.value.type === 'mailboxes') data.connection_token = connectionToken.value || null;
        if (data.filters) data.filters = clean(data.filters);
        if (modal.value.type === 'agents' && modal.value.id && !data.password) delete data.password;
        await api('manage/' + modal.value.type + (modal.value.id ? '/' + modal.value.id : ''), { method: modal.value.id ? 'PUT' : 'POST', body: data });
        modal.value = null; await bootstrap(); state.refresh++; notify('Changes saved');
    } catch (e) { formError.value = e.message; } finally { saving.value = false; }
}
async function remove() {
    saving.value = true;
    try {
        const body = deleting.value.type === 'mailboxes' ? { delete_tickets: deleting.value.delete_tickets === true } : undefined;
        const result = await api('manage/' + deleting.value.type + '/' + deleting.value.id, { method: 'DELETE', body });
        deleting.value = null; await bootstrap(); state.refresh++;
        notify(result.deleted_tickets ? `Account removed and ${result.deleted_tickets} ticket${result.deleted_tickets === 1 ? '' : 's'} deleted` : 'Removed successfully');
    }
    catch (e) { notify(e.message, true); } finally { saving.value = false; }
}
async function syncMailbox(box) {
    try { const result = await api('mailboxes/' + box.id + '/sync', { method: 'POST' }); notify(result.message); await bootstrap(); }
    catch (e) { notify(e.message, true); }
}
async function saveGeneral() {
    saving.value = true;
    try { await api('settings', { method: 'PUT', body: general }); await bootstrap(); notify('Workspace settings saved'); }
    catch (e) { notify(e.message, true); } finally { saving.value = false; }
}
async function savePreferences(theme = null) {
    if (theme) preferences.theme = theme;
    saving.value = true;
    try { state.user = await api('profile', { method: 'PATCH', body: { preferences } }); applyTheme(); notify('Preferences saved'); }
    catch (e) { applyTheme(); notify(e.message, true); } finally { saving.value = false; }
}
async function saveProfile() {
    saving.value = true;
    try {
        const data = { name: profile.name, email: profile.email };
        if (profile.password) Object.assign(data, { password: profile.password, password_confirmation: profile.password_confirmation, current_password: profile.current_password });
        state.user = await api('profile', { method: 'PATCH', body: data });
        profile.password = ''; profile.password_confirmation = ''; profile.current_password = ''; notify('Profile updated');
    } catch (e) { notify(e.message, true); } finally { saving.value = false; }
}
async function loadReports() {
    reportError.value = '';
    try { reports.value = await api('reports?days=' + days.value); } catch (e) { reportError.value = e.message; }
}
async function loadActivity(append = false) {
    try { const data = await api('activity?page=' + activityPage.value); activities.value = append ? [...activities.value, ...data.data] : data.data; activityLastPage.value = data.last_page; }
    catch (e) { notify(e.message, true); }
}
watch([page, days], () => { if (page.value === 'reports') loadReports(); if (page.value === 'activity') loadActivity(); }, { immediate: true });
const trendMax = computed(() => Math.max(1, ...(reports.value?.trend.map(d => Math.max(d.created, d.resolved)) || [])));
function chartPath(key) { return reports.value.trend.map((d, i, a) => (i ? 'L' : 'M') + (30 + i / Math.max(1, a.length - 1) * 900) + ',' + (220 - d[key] / trendMax.value * 170)).join(' '); }
function exportReport() {
    if (!reports.value) return;
    const content = 'Date,Created,Resolved\r\n' + reports.value.trend.map(d => [d.date, d.created, d.resolved].join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([content], { type: 'text/csv' }));
    const a = document.createElement('a'); a.href = url; a.download = 'relay-report-' + days.value + '-days.csv'; a.click(); URL.revokeObjectURL(url);
}
</script>
<template>
<main class="workspace-page surface">
    <header class="workspace-page-heading"><button class="icon-button mobile-only" @click="state.mobileNav = true" aria-label="Open navigation"><Icon name="menu" /></button><div><h1>{{ title }}</h1><p>{{ descriptions[title] }}</p></div><div class="workspace-heading-actions">
        <button v-if="page === 'settings' && section === 'views'" class="primary-button" @click="open('views')"><Icon name="plus" />Create view</button>
        <button v-if="page === 'settings' && section === 'accounts' && admin" class="primary-button" @click="open('mailboxes')"><Icon name="plus" />Add account</button>
        <button v-if="page === 'replies'" class="primary-button" @click="open('replies')"><Icon name="plus" />New response</button>
        <template v-if="page === 'reports'"><select v-model="days" aria-label="Report period"><option :value="7">Last 7 days</option><option :value="30">Last 30 days</option><option :value="90">Last 90 days</option></select><button class="secondary-button" @click="exportReport" :disabled="!reports"><Icon name="download" />Export</button></template>
    </div></header>
    <div class="workspace-content">
        <template v-if="page === 'settings'">
            <form v-if="section === 'general'" class="settings-form" @submit.prevent="saveGeneral">
                <div class="section-intro"><span class="setting-icon"><Icon name="settings" :size="24" /></span><div><h2>Workspace details</h2><p>The name and timezone used throughout your workspace.</p></div></div>
                <label>Workspace name<input v-model="general.name" required maxlength="100" :disabled="!admin" /></label>
                <label>Timezone<select v-model="general.timezone" :disabled="!admin"><option>Asia/Riyadh</option><option>UTC</option><option>Asia/Dubai</option><option>Europe/London</option><option>America/New_York</option><option>America/Los_Angeles</option><option>Asia/Kolkata</option><option>Asia/Singapore</option><option>Australia/Sydney</option></select></label>
                <div class="settings-divider" /><div class="setting-row"><div><h3>Workspace access</h3><p>Signed-in agents can access conversations and attachments.</p></div><span class="status-badge solved"><Icon name="lock" :size="13" />Private</span></div>
                <div class="setting-row"><div><h3>New account registration</h3><p>Keep this off for personal use. When enabled, an administrator can add accounts from Groups &amp; agents.</p></div><span class="status-badge" :class="state.workspace.registration_enabled ? 'open' : 'closed'">{{ state.workspace.registration_enabled ? 'Enabled' : 'Disabled' }}</span></div>
                <label class="check-label"><input v-model="general.registration_enabled" type="checkbox" :disabled="!admin" />Allow additional accounts</label>
                <p class="form-description">Existing accounts can still sign in. Your customers can email you without creating an account.</p>
                <div class="settings-divider" /><div class="setting-row"><div><h3>Email images</h3><p>Choose how images appear in conversations.</p></div></div>
                <label class="check-label"><input v-model="general.show_email_images" type="checkbox" :disabled="!admin" />Show email images automatically</label>
                <p class="form-description">Enabled by default. When disabled, click Show images on an email to load its images. Click a displayed image to enlarge it.</p>
                <div class="form-actions" v-if="admin"><button class="primary-button" :disabled="saving">Save changes</button></div>
                <Link class="text-button" :href="$appUrl('/activity')"><Icon name="activity" />View activity log<Icon name="right" :size="14" /></Link>
            </form>
            <template v-else-if="section === 'translation'"><TranslationSettings v-if="admin" /><p v-else class="muted">Translation settings are managed by your administrator.</p></template>
            <template v-else-if="section === 'custom-fields'"><CustomFieldSettings v-if="admin" /><p v-else class="muted">Custom fields are managed by your administrator.</p></template>
            <template v-else-if="section === 'mail-policy'"><MailPolicySettings v-if="admin" /><p v-else class="muted">Mail policies are managed by your administrator.</p></template>
            <template v-else-if="section === 'api'"><ApiSettings v-if="admin" /><p v-else class="muted">API access is managed by your administrator.</p></template>
            <template v-else-if="section === 'views'">
                <div class="info-banner"><Icon name="filters" /><p>All conditions must match. Views apply within the selected email account or group.</p></div>
                <div class="management-list"><article v-for="view in state.workspace.views" :key="view.id" class="management-row"><span class="setting-icon small"><Icon name="tag" /></span><div class="management-row-main"><h3>{{ view.name }}</h3><div class="criteria-chips"><span v-if="view.tag">Tag: {{ view.tag }}</span><span v-for="(value, key) in view.filters" :key="key">{{ fields.find(f => f[0] === key)?.[1] }}: {{ label(key, value) }}</span><span v-if="!view.tag && !Object.keys(view.filters || {}).length">All inbox tickets</span></div></div><button class="secondary-button compact" @click="open('views', view)">Edit</button><button class="icon-button" @click="deleting = { type: 'views', id: view.id, name: view.name }" :aria-label="'Remove view ' + view.name"><Icon name="trash" :size="16" /></button></article></div>
                <div v-if="!state.workspace.views.length" class="empty-state"><Icon name="tag" :size="30" /><h2>A view for every kind of work.</h2><p>Create a view for urgent requests, billing questions, or your team’s open tickets.</p><button class="primary-button" @click="open('views')">Create your first view</button></div>
            </template>
            <template v-else-if="section === 'accounts'">
                <SendingSafety />
                <div class="account-grid"><article v-for="box in state.workspace.mailboxes" :key="box.id" class="account-card"><div class="account-card-top"><span class="account-symbol" :style="{ '--mailbox-color': box.color }"><Icon name="mail" :size="23" /></span><span class="status-badge" :class="state.workspace.sending_safety?.paused ? 'on-hold' : box.sending_enabled ? 'solved' : 'closed'"><span class="tiny-dot" />{{ state.workspace.sending_safety?.paused ? 'Sending paused' : box.sending_enabled ? 'Sending enabled' : 'Not connected' }}</span></div><h2>{{ box.name }}</h2><p>{{ box.email }}</p><div class="account-meta"><span><Icon name="users" :size="14" />{{ state.workspace.teams.find(t => t.id === box.team_id)?.name || 'No group' }}</span><span>SMTP {{ state.workspace.sending_safety?.paused ? 'paused' : box.sending_enabled ? 'on' : 'off' }} · IMAP {{ box.incoming_enabled ? 'on' : 'off' }}</span></div><p v-if="box.sync_error" class="danger">{{ box.sync_error }}</p><p v-else-if="box.last_synced_at">Last synced {{ relativeTime(box.last_synced_at) }} ago</p><footer v-if="admin"><button v-if="box.incoming_enabled" class="icon-button" @click="syncMailbox(box)" :aria-label="'Sync ' + box.name" title="Sync incoming mail"><Icon name="inbox" :size="16" /></button><button class="text-button" @click="open('mailboxes', box)">Manage account<Icon name="right" :size="15" /></button><button class="icon-button" @click="deleting = { type: 'mailboxes', id: box.id, name: box.name }" aria-label="Remove email account"><Icon name="trash" :size="16" /></button></footer></article>
                <button v-if="admin" class="account-card add-account" @click="open('mailboxes')"><span class="setting-icon"><Icon name="plus" :size="24" /></span><h3>Add an email account</h3><p>Give every conversation a home.</p></button></div>
                <div class="info-banner"><Icon name="mail" /><p>Connect SMTP to send replies and IMAP to receive new conversations. Only emails received after adding the account become tickets. Older emails stay in your mailbox. Removing tickets or disconnecting an account here never deletes emails from your provider.</p></div>
            </template>
            <template v-else-if="section === 'groups'">
                <div class="subsection-heading"><div><h2>Groups</h2><p>Bring related email accounts and tickets together.</p></div><button v-if="admin" class="secondary-button" @click="open('teams')"><Icon name="plus" />New group</button></div>
                <div class="management-list"><article v-for="team in state.workspace.teams" :key="team.id" class="management-row"><span class="setting-icon small"><Icon name="users" /></span><div class="management-row-main"><h3>{{ team.name }}</h3><p>{{ team.description || 'No description' }} · {{ state.workspace.mailboxes.filter(b => b.team_id === team.id).length }} accounts</p></div><template v-if="admin"><button class="secondary-button compact" @click="open('teams', team)">Edit</button><button class="icon-button" @click="deleting = { type: 'teams', id: team.id, name: team.name }" aria-label="Remove group"><Icon name="trash" :size="16" /></button></template></article></div>
                <div class="subsection-heading spaced"><div><h2>Agents</h2><p>The people behind every great reply.</p></div><button v-if="admin && state.workspace.registration_enabled" class="secondary-button" @click="open('agents')"><Icon name="plus" />Add agent</button></div>
                <div v-if="!state.workspace.registration_enabled" class="info-banner"><Icon name="lock" /><p>New account registration is disabled. <Link v-if="admin" :href="$appUrl('/settings?section=general')" class="text-button">Manage workspace access</Link></p></div>
                <div class="management-list"><article v-for="agent in state.workspace.agents" :key="agent.id" class="management-row"><span class="avatar">{{ initials(agent.name) }}</span><div class="management-row-main"><h3>{{ agent.name }} <span v-if="agent.id === state.user.id" class="muted">(you)</span></h3><p>{{ agent.email }}</p></div><span class="role-badge">{{ agent.role }}</span><button v-if="admin" class="secondary-button compact" @click="open('agents', agent)">Edit</button></article></div>
            </template>
            <template v-else-if="section === 'themes'">
                <div class="subsection-heading"><div><h2>Choose your atmosphere</h2><p>A considered palette for every kind of day.</p></div><span class="muted">Saved to your profile</span></div>
                <div class="theme-grid"><button v-for="palette in palettes" :key="palette.id" class="theme-choice" :class="{ selected: preferences.theme === palette.id }" :aria-pressed="preferences.theme === palette.id" :disabled="saving" @click="savePreferences(palette.id)">
                    <div class="theme-preview" :style="{ background: palette.background }"><i :style="{ background: palette.rail }"><span /><span /><span /></i><div class="theme-preview-sidebar" :style="{ background: palette.surface }"><b :style="{ background: palette.accent + '22' }" /><b /><b /><b /></div><div class="theme-preview-main" :style="{ background: palette.surface }"><span :style="{ background: palette.accent }" /><b /><b /><b /></div></div>
                    <span class="theme-label"><strong>{{ palette.name }}</strong><span v-if="preferences.theme === palette.id" class="theme-selected"><Icon name="solved" :size="16" />Selected</span><span v-else class="muted">Choose</span></span>
                </button></div>
                <form class="custom-theme-form" @submit.prevent="savePreferences('custom')"><div class="subsection-heading"><div><h2>Make it yours</h2><p>Choose custom colors. Text and badges adjust for readability.</p></div><button class="primary-button" :disabled="saving">Apply custom colors</button></div><div class="custom-colors"><label v-for="[key, text] in [['accent', 'Accent'], ['rail', 'Navigation'], ['background', 'Background'], ['surface', 'Panels']]" :key="key">{{ text }}<div><input type="color" v-model="preferences.colors[key]" :aria-label="text + ' color'" /><input v-model="preferences.colors[key]" pattern="#[0-9a-fA-F]{6}" maxlength="7" :aria-label="text + ' hex code'" /></div></label></div></form>
            </template>
            <form v-else-if="section === 'inbox'" class="settings-form" @submit.prevent="savePreferences()">
                <div class="section-intro"><span class="setting-icon"><Icon name="inbox" :size="24" /></span><div><h2>Start your day in the right place</h2><p>These preferences are saved to your account.</p></div></div>
                <label>Initial inbox view<select v-model="preferences.initial_view"><option value="all">All tickets</option><option value="mine">Assigned to me</option><option value="unassigned">Unassigned</option><option value="unread">Unread</option><option value="undelivered">Undelivered</option><option v-for="status in state.workspace.statuses" :key="status">{{ status }}</option></select></label>
                <div class="setting-row"><div><h3>Email source indicators</h3><p>Show the destination mailbox beside ticket tags.</p></div><label class="switch"><input type="checkbox" v-model="preferences.source_indicators" aria-label="Show email source indicators" /><span /></label></div><div class="form-actions"><button class="primary-button" :disabled="saving">Save preferences</button></div>
            </form>
        </template>
        <template v-else-if="page === 'replies'">
            <div class="collection-toolbar"><label class="collection-search"><Icon name="search" /><input v-model="search" placeholder="Search responses…" aria-label="Search responses" /></label><span class="muted">{{ filteredReplies.length }} responses</span></div>
            <div class="reply-grid"><article v-for="reply in filteredReplies" :key="reply.id" class="reply-card"><div><span class="category-badge">{{ reply.category }}</span><details class="row-menu"><summary class="icon-button" :aria-label="'Options for ' + reply.title"><Icon name="more" /></summary><div class="dropdown-menu"><button @click="open('replies', reply)">Edit response</button><button @click="deleting = { type: 'replies', id: reply.id, name: reply.title }">Remove response</button></div></details></div><button class="reply-title" @click="open('replies', reply)">{{ reply.title }}</button><p>{{ reply.body }}</p><footer><kbd>{{ reply.shortcut }}</kbd><button class="text-button" @click="open('replies', reply)">Edit response<Icon name="right" :size="14" /></button></footer></article></div>
            <div v-if="!filteredReplies.length" class="empty-state"><Icon name="message" :size="30" /><h2>No responses yet</h2><p>Create a reusable response or try another search.</p><button class="primary-button" @click="open('replies')">New response</button></div>
            <div class="info-banner"><Icon name="bolt" /><p>Type a response shortcut in the reply editor, then press Tab. Variables such as <code v-pre>{{name}}</code> are filled in for the current conversation.</p></div>
        </template>
        <template v-else-if="page === 'reports'">
            <p v-if="!reports && !reportError" class="muted">Gathering your reports…</p><div v-if="reportError" class="error-message" role="alert">{{ reportError }}<button @click="loadReports">Try again</button></div>
            <template v-if="reports">
                <div class="metric-grid"><article v-for="[text, value, icon, note] in [['New tickets', reports.total, 'inbox', 'Created in this period'], ['Resolved tickets', reports.resolved, 'solved', 'From tickets created in this period'], ['Resolution rate', reports.resolution_rate + '%', 'chart', 'Of tickets created in this period'], ['First response', reports.response_minutes === null ? '—' : reports.response_minutes < 60 ? reports.response_minutes + 'm' : (reports.response_minutes / 60).toFixed(1) + 'h', 'clock', 'Average · excludes automated replies']]" :key="text" class="metric-card"><div><span>{{ text }}</span><Icon :name="icon" /></div><strong>{{ value }}</strong><small>{{ note }}</small></article></div>
                <section class="report-card"><div class="subsection-heading"><div><h2>Conversation volume</h2><p>A day-by-day look at requests and resolutions.</p></div><div class="chart-legend"><span><i />Created</span><span><i />Resolved</span></div></div>
                    <svg class="report-chart" viewBox="0 0 960 260" role="img" :aria-label="'Conversation volume over ' + days + ' days'"><line v-for="n in 5" :key="n" x1="30" x2="930" :y1="50 + (n - 1) * 42.5" :y2="50 + (n - 1) * 42.5" /><text x="6" y="54">{{ trendMax }}</text><text x="12" y="224">0</text><path :d="chartPath('created') + ' L930,220 L30,220 Z'" class="chart-area" /><path :d="chartPath('created')" class="chart-line" /><path :d="chartPath('resolved')" class="chart-line resolved" /><template v-for="(point, i) in reports.trend" :key="point.date"><circle :cx="30 + i / Math.max(1, reports.trend.length - 1) * 900" :cy="220 - point.created / trendMax * 170" r="3" class="chart-point"><title>{{ point.date }}: {{ point.created }} created, {{ point.resolved }} resolved</title></circle></template><text x="30" y="251">{{ reports.trend[0]?.date }}</text><text x="850" y="251">{{ reports.trend.at(-1)?.date }}</text></svg>
                </section>
                <div class="report-grid"><section class="report-card"><h2>Tickets by status</h2><div v-for="status in state.workspace.statuses" :key="status" class="status-report-row"><div><span class="status-dot" :class="status.toLowerCase().replaceAll(' ', '-')" />{{ status }}<strong>{{ reports.statuses[status] || 0 }}</strong></div><div class="progress-track"><span :style="{ width: (reports.statuses[status] || 0) / Math.max(1, reports.total) * 100 + '%' }" /></div></div></section>
                    <section class="report-card"><h2>Team overview</h2><div class="agent-report-head"><span>Agent</span><span>Assigned</span><span>Resolved</span></div><div v-for="agent in reports.agents" :key="agent.name" class="agent-report-row"><span><span class="mini-avatar">{{ initials(agent.name) }}</span>{{ agent.name }}</span><strong>{{ agent.assigned }}</strong><strong>{{ agent.resolved }}</strong></div></section></div>
                <p class="report-note">Reports use your saved conversations. Sample tickets, if added during setup, are included.</p>
            </template>
        </template>
        <template v-else-if="page === 'profile'">
            <form class="settings-form" @submit.prevent="saveProfile"><div class="profile-overview"><span class="avatar large">{{ initials(state.user.name) }}</span><div><h2>{{ state.user.name }}</h2><span class="role-badge">{{ state.user.role }}</span></div></div><label>Full name<input v-model="profile.name" required /></label><label>Email address<input v-model="profile.email" type="email" required /></label><div class="settings-divider" /><h3>Change password</h3><p class="muted">Leave these fields empty to keep your current password.</p><label>Current password<input v-model="profile.current_password" type="password" autocomplete="current-password" /></label><div class="form-grid"><label>New password<input v-model="profile.password" type="password" minlength="12" autocomplete="new-password" /></label><label>Confirm new password<input v-model="profile.password_confirmation" type="password" autocomplete="new-password" /></label></div><div class="form-actions"><button class="primary-button" :disabled="saving">Save profile</button></div></form>
            <form class="settings-form signature-form" @submit.prevent="savePreferences()"><h2>Email signature</h2><p class="muted">Insert this signature from the reply editor.</p><label>Signature<textarea v-model="preferences.signature" rows="4" placeholder="Best regards,&#10;Your name" /></label><div class="form-actions"><button class="secondary-button" :disabled="saving">Save signature</button></div></form>
        </template>
        <template v-else-if="page === 'activity'"><div class="activity-list"><article v-for="entry in activities" :key="entry.id"><span class="setting-icon small"><Icon name="activity" /></span><div><Link v-if="entry.ticket_id" :href="$appUrl('/tickets/' + entry.ticket_id)">{{ entry.description }}</Link><span v-else>{{ entry.description }}</span><small>{{ new Date(entry.created_at).toLocaleString() }}</small></div><span>{{ relativeTime(entry.created_at) }}</span></article></div><div v-if="!activities.length" class="empty-state"><Icon name="activity" /><p>Activity will appear as your team works.</p></div><button v-if="activityPage < activityLastPage" class="secondary-button load-more" @click="activityPage++; loadActivity(true)">Load more activity</button></template>
    </div>
</main>

<Modal v-if="modal" :title="modal.title" wide @close="!saving && (modal = null)">
<form class="form-stack" @submit.prevent="save">
    <div v-if="formError" class="error-message" role="alert">{{ formError }}</div>
    <template v-if="modal.type === 'views'">
        <label>View name<input v-model="form.name" required placeholder="e.g. Urgent billing requests" maxlength="80" /></label>
        <label>Tag <span class="muted">(optional)</span><input v-model="form.tag" placeholder="Any tag" maxlength="60" /></label>
        <div class="form-section-label"><span>ALL OF THESE CONDITIONS</span></div>
        <div class="form-grid"><label v-for="[key, text, options] in fields" :key="key">{{ text }}<select v-model="form.filters[key]"><option value="">Any {{ text.toLowerCase() }}</option><option v-for="[value, text] in options()" :value="value" :key="value">{{ text }}</option></select></label></div>
        <p class="form-description">This view includes inbox tickets that match every condition, within the selected mailbox or group.</p>
    </template>
    <template v-else-if="modal.type === 'replies'">
        <label>Response title<input v-model="form.title" required placeholder="A friendly follow-up" maxlength="120" /></label>
        <div class="form-grid"><label>Shortcut<input v-model="form.shortcut" required pattern="#[a-zA-Z0-9_-]+" placeholder="#followup" /></label><label>Category<input v-model="form.category" required placeholder="General" /></label></div>
        <label>Message<textarea v-model="form.body" required rows="9" placeholder="Hi {{name}},&#10;&#10;Thanks for reaching out…" /></label>
        <div class="variable-options"><span>Insert a variable</span><button v-for="variable in ['name', 'email', 'ticket_id', 'subject', 'agent']" :key="variable" type="button" @click="form.body += '{{' + variable + '}}'">{{ ['{', '{', variable, '}', '}'].join('') }}</button></div>
    </template>
    <template v-else-if="modal.type === 'teams'"><label>Group name<input v-model="form.name" required placeholder="Customer success" /></label><label>Description<textarea v-model="form.description" rows="3" placeholder="What does this group take care of?" /></label></template>
    <template v-else-if="modal.type === 'agents'"><label>Full name<input v-model="form.name" required /></label><label>Email address<input v-model="form.email" type="email" required /></label><label>Role<select v-model="form.role"><option value="agent">Agent — tickets, replies, and views</option><option value="admin">Admin — full workspace access</option></select></label><label>{{ modal.id ? 'New password (leave blank to keep current)' : 'Initial password' }}<input v-model="form.password" type="password" minlength="12" :required="!modal.id" autocomplete="new-password" /></label><p class="form-description">Share the account details with your teammate directly. They can change their password from their profile.</p></template>
    <template v-else-if="modal.type === 'mailboxes'">
        <div class="form-grid"><label>Account name<input v-model="form.name" required placeholder="Customer support" /></label><label>Email address<input v-model="form.email" type="email" required placeholder="support@company.com" /></label><label>Group<select v-model="form.team_id"><option :value="null">No group</option><option v-for="team in state.workspace.teams" :key="team.id" :value="team.id">{{ team.name }}</option></select></label><label>Source color<input v-model="form.color" type="color" /></label></div>
        <div class="form-section-label"><Icon name="send" :size="15" /><span>OUTGOING MAIL · SMTP</span></div>
        <div class="form-grid"><label>SMTP host<input v-model="form.smtp_host" placeholder="smtp.company.com" :required="!modal.id || form.sending_enabled" /></label><label>Port<input v-model.number="form.smtp_port" type="number" min="1" max="65535" required /></label><label>Username<input v-model="form.smtp_username" autocomplete="off" /></label><label>Password<input v-model="form.smtp_password" type="password" autocomplete="new-password" :placeholder="form.has_smtp_password ? 'Saved password · leave blank to keep' : 'SMTP password'" /></label><label>Encryption<select v-model="form.smtp_encryption"><option value="tls">STARTTLS</option><option value="ssl">SSL / TLS</option></select></label></div>
        <label class="check-label"><input v-model="form.sending_enabled" type="checkbox" />Enable outgoing email for this account</label>
        <p class="form-description">Replies are sent when this account is enabled and workspace bounce protection permits sending.</p>
        <div class="form-section-label"><Icon name="inbox" :size="15" /><span>INCOMING MAIL · IMAP</span></div><p class="form-description">Receive messages from your INBOX over TLS. Replies are linked to the same requester and ticket; repeat syncs skip already imported messages.</p>
        <div class="form-grid"><label>IMAP host<input v-model="form.imap_host" placeholder="imap.company.com" :required="!modal.id || form.incoming_enabled" /></label><label>Port<input v-model.number="form.imap_port" type="number" min="1" max="65535" required /></label><label>Username<input v-model="form.imap_username" autocomplete="off" :required="!modal.id || form.incoming_enabled" /></label><label>Password<input v-model="form.imap_password" type="password" autocomplete="new-password" :placeholder="form.has_imap_password ? 'Saved password · leave blank to keep' : 'IMAP password'" /></label></div>
    </template>
    <template v-if="modal.type === 'mailboxes'"><label>IMAP encryption<select v-model="form.imap_encryption"><option value="ssl">SSL / TLS</option><option value="tls">STARTTLS</option></select></label><label class="check-label"><input v-model="form.incoming_enabled" type="checkbox" />Enable incoming email synchronization</label><p class="form-description">New emails are checked automatically from the time this account is added. Existing mail is not imported. Each email is added once, and your provider’s read status is preserved. Incoming mail continues when outgoing sending is paused.</p></template>
    <section v-if="modal.type === 'mailboxes'" class="connection-test-panel" aria-label="Email connection tests">
        <div class="subsection-heading"><div><h3>Test connections</h3><p>Both SMTP and IMAP must pass before adding an account or changing its connection settings.</p></div><button type="button" class="secondary-button" :disabled="saving || testingConnections" @click="testConnections"><Icon :name="testingConnections ? 'loader' : 'refresh'" :class="{ spin: testingConnections }" />{{ testingConnections ? 'Testing…' : 'Test SMTP & IMAP' }}</button></div>
        <p class="muted">Uses the settings above. No email is sent or imported, and sending stays paused if receive-only mode is active.</p>
        <div v-if="testingConnections" class="info-banner" role="status"><Icon name="loader" class="spin" /><p>Checking secure connections and account login…</p></div>
        <div v-if="connectionResults" class="connection-test-results" aria-live="polite">
            <div v-for="protocol in ['smtp', 'imap']" :key="protocol" class="connection-test-result" :class="{ failed: !connectionResults[protocol].success }"><span class="status-badge" :class="connectionResults[protocol].success ? 'solved' : 'closed'"><Icon :name="connectionResults[protocol].success ? 'check' : 'alert'" :size="15" />{{ protocol.toUpperCase() }} · {{ connectionResults[protocol].success ? 'Passed' : 'Failed' }}</span><p>{{ connectionResults[protocol].message }}</p></div>
        </div>
        <p v-if="connectionError" class="error-message" role="alert">{{ connectionError }}</p>
        <p v-if="connectionsPassed" class="muted" role="status">Both connections passed. You can save now. Changing connection settings requires another test.</p>
        <p v-else-if="connectionTestRequired && !testingConnections" class="muted">Save is available after both connections pass. Results are valid for 10 minutes.</p>
    </section>
    <div class="form-actions"><button type="button" class="secondary-button" @click="modal = null" :disabled="saving">Cancel</button><button class="primary-button" :disabled="saving || (modal.type === 'mailboxes' && !canSaveAccount)">{{ saving ? 'Saving…' : modal.type === 'mailboxes' && !modal.id ? 'Add account' : 'Save changes' }}</button></div>
</form>
</Modal>
<Modal v-if="deleting" :title="deleting.type === 'mailboxes' ? 'Remove email account?' : 'Remove this item?'" @close="!saving && (deleting = null)">
    <p class="delete-description">Remove <strong>{{ deleting.name }}</strong> from the workspace?</p>
    <template v-if="deleting.type === 'mailboxes'">
        <label class="check-label"><input v-model="deleting.delete_tickets" type="checkbox" :disabled="saving" />Also delete all tickets for this email account</label>
        <p v-if="deleting.delete_tickets" class="error-message" role="alert">All tickets for this account, including archived, spam, trash and merged conversations, will be permanently deleted with their messages and uploaded files. This cannot be undone.</p>
        <p v-else class="form-description">Your ticket conversations will be kept.</p>
        <p class="form-description">This disconnects the account from Relay and revokes its API keys. Emails at your provider are untouched. Adding it again starts with new emails received from that time.</p>
    </template>
    <p v-else class="form-description">Your ticket conversations will be kept.</p>
    <div class="form-actions"><button class="secondary-button" @click="deleting = null" :disabled="saving">Cancel</button><button class="danger-button" @click="remove" :disabled="saving">{{ saving ? 'Removing…' : deleting.type === 'mailboxes' && deleting.delete_tickets ? 'Remove account and delete tickets' : 'Remove' }}</button></div>
</Modal>
</template>
