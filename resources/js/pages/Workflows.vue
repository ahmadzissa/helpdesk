<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { state, api, notify } from '../store';
import Modal from '../components/Modal.vue';
import ActionEditor from '../components/ActionEditor.vue';
import ConditionEditor from '../components/ConditionEditor.vue';
import { workflowTemplates } from '../workflowTemplates';
const tab = ref('rules'), data = ref({ rules: [], macros: [], runs: [], follow_ups: [] }), loading = ref(true), error = ref(''), editing = ref(false), saving = ref(false), removing = ref(null), form = reactive({});
const admin = computed(() => state.user.role === 'admin');
const triggers = { 'ticket.created': 'Ticket is created', 'ticket.updated': 'Ticket details change', 'message.received': 'Customer message arrives', 'message.sent': 'Agent reply is saved', 'time.elapsed': 'Time passes (checked every minute)' };
const normalizeActions = actions => Array.isArray(actions) ? actions : Object.entries(actions || {}).filter(([, value]) => value !== null && value !== '').map(([type, value]) => ({ type: type === 'tag' ? 'add_tag' : type, value }));
function normalizeConditions(conditions = {}) { return conditions.all || conditions.any ? { all: conditions.all || [], any: conditions.any || [] } : { all: Object.entries(conditions).filter(([, v]) => v !== null && v !== '').map(([key, value]) => ({ field: key === 'subject_contains' ? 'subject' : key, operator: key === 'subject_contains' ? 'contains' : 'eq', value: String(value) })), any: [] }; }
const requestedRule = new URLSearchParams(window.location.search).get('rule') || '';
const focusedName = requestedRule.replace(/^Macro: /, '');
async function load() {
    try { data.value = await api('workflows'); }
    catch (e) { notify(e.message, true); }
    finally { loading.value = false; }
    if (requestedRule) {
        tab.value = requestedRule.startsWith('Macro: ') ? 'macros' : 'rules';
        await nextTick(); document.querySelector('.automation-card.is-referenced')?.scrollIntoView({ block: 'center' });
    }
}
function edit(record = null) {
    Object.keys(form).forEach(key => delete form[key]);
    Object.assign(form, { name: '', description: '', enabled: tab.value === 'macros', trigger: 'ticket.created', repeat_mode: 'once', interval_minutes: 60, max_runs: 100, conditions: { all: [], any: [] }, actions: [{ type: 'status', value: 'Open' }] }, record ? JSON.parse(JSON.stringify(record)) : {});
    form.actions = normalizeActions(form.actions); form.conditions = normalizeConditions(form.conditions); error.value = ''; editing.value = true;
}
async function save(record = form) {
    saving.value = true; error.value = '';
    try { const payload = { ...record, conditions: normalizeConditions(record.conditions), actions: normalizeActions(record.actions) }; await api('workflows/' + tab.value + (record.id ? '/' + record.id : ''), { method: record.id ? 'PUT' : 'POST', body: payload }); editing.value = false; await load(); notify('Workflow saved'); }
    catch (e) { error.value = e.message; if (!editing.value) notify(e.message, true); } finally { saving.value = false; }
}
async function remove() { try { await api('workflows/' + tab.value + '/' + removing.value.id, { method: 'DELETE' }); removing.value = null; await load(); } catch (e) { notify(e.message, true); } }
const when = date => new Date(date.endsWith?.('Z') || date.includes?.('T') ? date : date + 'Z').toLocaleString();
const actionSummary = action => ({ status: 'Set status', priority: 'Set priority', folder: 'Move to', assignee_id: 'Assign agent', team_id: 'Assign team', add_tag: 'Add tag', remove_tag: 'Remove tag', reply_id: 'Send canned response', note: 'Private note', follow_up: 'Follow up in minutes', send_message: 'Send message', send_follow_up: 'Send follow-up' }[action.type]) + ': ' + (action.type === 'reply_id' ? state.workspace.replies.find(r => r.id === Number(action.value))?.title || action.value : ['note', 'send_message', 'send_follow_up'].includes(action.type) ? action.value.slice(0, 120) : action.value ?? 'Unassigned');
onMounted(load);
</script>
<template>
<main class="workspace-page surface"><header class="workspace-page-heading"><button class="icon-button mobile-only" @click="state.mobileNav = true" aria-label="Open navigation"><Icon name="menu" /></button><div><h1>Automations & macros</h1><p>Consistent actions. Timely conversations.</p></div><button v-if="admin && ['rules', 'macros'].includes(tab)" class="primary-button" @click="edit()"><Icon name="plus" />{{ tab === 'rules' ? 'Create rule' : 'Create macro' }}</button></header>
<div class="workspace-content"><nav class="workflow-tabs" aria-label="Workflow sections"><button v-for="[id, label] in [['rules', 'Automation rules'], ['macros', 'Macros'], ['runs', 'Run history'], ['follow_ups', 'Follow-ups']]" :key="id" :class="{ active: tab === id }" @click="tab = id">{{ label }}</button></nav>
<div class="info-banner"><Icon name="lock" /><p>All outgoing actions obey bounce protection, blocked senders, and recipient suppression. Held replies need individual review after sending is reactivated.</p></div>
<p v-if="loading" class="muted">Loading workflows…</p>
<template v-else-if="['rules', 'macros'].includes(tab)">
<section v-if="tab === 'rules' && admin" class="workflow-presets"><h2>Start with a rule</h2><p class="muted">Choose a preset, review its conditions and message, then save. Presets start paused.</p><div class="preset-buttons"><button v-for="preset in workflowTemplates" :key="preset.name" class="secondary-button" @click="edit(preset)">{{ preset.name }}</button></div></section>
<p v-if="requestedRule && !data[tab].some(record => record.name === focusedName)" class="muted">“{{ requestedRule }}” is no longer listed here. It may have been renamed or removed.</p>
<div class="automation-list"><article v-for="record in data[tab]" :key="record.id" class="automation-card" :class="{ 'is-referenced': record.name === focusedName }">
<div class="automation-card-heading"><span class="setting-icon small"><Icon name="bolt" /></span><div><h3>{{ record.name }}</h3><span class="muted">{{ record.enabled ? 'Active' : 'Paused' }}<template v-if="tab === 'rules'"> · {{ triggers[record.trigger] }} · {{ record.repeat_mode === 'event' ? 'Each matching event' : record.repeat_mode === 'interval' ? (record.trigger === 'time.elapsed' ? 'Every ' : 'At most once every ') + record.interval_minutes + ' minutes' : 'Once per ticket' }}</template></span></div>
<label class="switch"><input type="checkbox" :checked="record.enabled" :disabled="!admin || saving" @change="save({ ...record, enabled: !record.enabled })" :aria-label="'Enable ' + record.name" /><span /></label><button v-if="admin" class="icon-button" @click="edit(record)" :aria-label="'Edit ' + record.name"><Icon name="edit" /></button><button v-if="admin" class="icon-button" @click="removing = record" :aria-label="'Remove ' + record.name"><Icon name="trash" /></button></div>
<div class="workflow-summary"><p v-if="record.description">{{ record.description }}</p><p v-if="tab === 'rules'" class="muted">{{ normalizeConditions(record.conditions).all.length }} required conditions · {{ normalizeConditions(record.conditions).any.length }} alternatives · Maximum {{ record.max_runs }} runs per ticket</p><ol><li v-for="(action, index) in normalizeActions(record.actions)" :key="index">{{ actionSummary(action) }}</li></ol></div></article></div>
<div v-if="!data[tab].length" class="empty-state"><Icon name="bolt" :size="30" /><h2>{{ tab === 'rules' ? 'Your first rule starts here.' : 'Several actions. One click.' }}</h2><p>{{ tab === 'rules' ? 'Organize tickets by their details or the time they have been waiting.' : 'Create a macro, then apply it from any ticket after reviewing its actions.' }}</p></div>
</template>
<div v-else class="workflow-history"><article v-for="entry in data[tab]" :key="entry.id" class="management-row"><Icon :name="tab === 'runs' ? 'bolt' : 'clock'" /><div class="management-row-main"><Link :href="$appUrl('/tickets/' + entry.ticket_id)">#{{ entry.ticket_id }} · {{ entry.name || 'Timed follow-up' }}</Link><p class="muted">{{ tab === 'runs' ? when(entry.created_at) : when(entry.due_at) + ' · ' + entry.state + (entry.result ? ' · ' + entry.result : '') }}</p></div></article><p v-if="!data[tab].length" class="muted">Nothing here yet.</p><small class="muted">Most recent 50 entries.</small></div>
</div></main>
<Modal v-if="editing" :title="(form.id ? 'Edit ' : 'Create ') + (tab === 'rules' ? 'automation rule' : 'macro')" wide @close="!saving && (editing = false)"><form class="form-stack" @submit.prevent="save()"><p v-if="error" class="error-message" role="alert">{{ error }}</p><label>Name<input v-model="form.name" required maxlength="120" /></label><label class="check-label"><input type="checkbox" v-model="form.enabled" />{{ tab === 'rules' ? 'Enable this rule' : 'Make this macro available' }}</label>
<template v-if="tab === 'rules'"><label>When<select v-model="form.trigger" @change="form.trigger === 'time.elapsed' && form.repeat_mode === 'event' && (form.repeat_mode = 'interval')"><option v-for="(label, value) in triggers" :key="value" :value="value">{{ label }}</option></select></label><div class="form-grid"><label>Repeat<select v-model="form.repeat_mode"><option value="once">Once per ticket</option><option v-if="form.trigger !== 'time.elapsed'" value="event">Each matching event</option><option value="interval">At most once per interval</option></select></label><label v-if="form.repeat_mode === 'interval'">Minimum interval (minutes)<input type="number" v-model.number="form.interval_minutes" min="1" max="525600" required /></label><label>Maximum runs per ticket<input type="number" v-model.number="form.max_runs" min="1" max="1000" required /></label></div><ConditionEditor v-model="form.conditions" /></template>
<label v-if="tab === 'rules'">Description<textarea v-model="form.description" rows="2" maxlength="2000" /></label>
<h3>Apply these actions in order</h3><ActionEditor v-model="form.actions" /><p class="muted">Messages and immediate follow-ups send when outgoing mail is available. Scheduled follow-ups send when due. Actions do not recursively trigger other rules.</p><div class="form-actions"><button type="button" class="secondary-button" @click="editing = false" :disabled="saving">Cancel</button><button class="primary-button" :disabled="saving || !form.actions.length">{{ saving ? 'Saving…' : 'Save workflow' }}</button></div></form></Modal>
<Modal v-if="removing" title="Remove workflow" @close="removing = null"><p>Remove “{{ removing.name }}”? Existing messages will be preserved.</p><div class="form-actions"><button class="secondary-button" @click="removing = null">Cancel</button><button class="primary-button" @click="remove">Remove workflow</button></div></Modal>
</template>
<style scoped>
.automation-card.is-referenced{outline:2px solid var(--accent);outline-offset:3px;scroll-margin-block:24px}
.workflow-presets{margin-bottom:26px}.workflow-presets h2{font-size:16px;margin-bottom:8px}.preset-buttons{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.preset-buttons button{font-size:12px;text-align:start}
</style>
