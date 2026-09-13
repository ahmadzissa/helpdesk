<script setup>
import { state } from '../store';
const conditions = defineModel({ type: Object, required: true });
const fields = [['status', 'Status'], ['priority', 'Priority'], ['folder', 'Folder'], ['source', 'Source'], ['assignee_id', 'Agent ID'], ['team_id', 'Team ID'], ['mailbox_id', 'Mailbox ID'], ['subject', 'Subject'], ['requester_email', 'Requester email'], ['requester_domain', 'Requester domain'], ['company', 'Company'], ['tag', 'Tag'], ['body', 'Latest customer message'], ['sender_trust', 'Sender trust'], ['age_minutes', 'Ticket age'], ['idle_minutes', 'Time since conversation activity'], ['since_customer_minutes', 'Time since customer message'], ['since_reply_minutes', 'Time since reply was saved'], ['since_status_minutes', 'Time since status changed'], ['since_events_minutes', 'Time since selected events'], ['customer_message_count', 'Requester messages received'], ['follow_up_count', 'Follow-ups sent'], ['follow_up_created_count', 'Follow-ups created or scheduled']];
const operators = [['eq', 'equals'], ['neq', 'does not equal'], ['contains', 'contains characters'], ['not_contains', 'does not contain characters'], ['contains_phrase', 'contains phrase'], ['not_contains_phrase', 'does not contain phrase'], ['starts_with', 'starts with'], ['ends_with', 'ends with'], ['empty', 'is empty'], ['not_empty', 'is not empty'], ['gte', 'is at least'], ['lte', 'is at most']];
const events = [['status_changed', 'Status changed'], ['message_sent', 'Message sent'], ['follow_up_sent', 'Follow-up sent'], ['activity', 'Any ticket activity']];
const numeric = field => field.endsWith('_minutes') || field.endsWith('_count');
const textField = field => ['subject', 'body'].includes(field);
const availableOperators = field => operators.filter(([op]) => numeric(field) ? ['eq', 'neq', 'gte', 'lte', 'empty', 'not_empty'].includes(op) : !op.includes('phrase') || textField(field));
const choices = field => ({
    status: state.workspace.statuses.map(v => [v, v]), priority: state.workspace.priorities.map(v => [v, v]),
    folder: ['inbox', 'archive', 'spam', 'trash'].map(v => [v, v]), source: ['Email', 'Web', 'Webhook', 'Phone'].map(v => [v, v]),
    sender_trust: ['trusted', 'blocked', 'neutral'].map(v => [v, v]),
    assignee_id: [['unassigned', 'Unassigned'], ...state.workspace.agents.map(v => [String(v.id), v.name])],
    team_id: state.workspace.teams.map(v => [String(v.id), v.name]),
    mailbox_id: state.workspace.mailboxes.map(v => [String(v.id), v.name]),
}[field]);
function change(condition) {
    condition.value = choices(condition.field)?.[0]?.[0] || (numeric(condition.field) ? '0' : '');
    condition.operator = condition.field.endsWith('_minutes') ? 'gte' : 'eq';
    delete condition.events; delete condition.scope; delete condition.case_sensitive;
    if (condition.field === 'since_events_minutes') condition.events = ['status_changed'];
    if (condition.field.startsWith('follow_up_')) condition.scope = 'anyone';
}
</script>
<template>
<div v-for="group in ['all', 'any']" :key="group" class="condition-group">
    <h3>{{ group === 'all' ? 'Match all of these' : 'Also match any of these' }}</h3>
    <p class="muted">{{ group === 'all' ? 'Every condition in this group must match.' : 'At least one condition must match. Leave empty to skip this group.' }}</p>
    <div v-for="(condition, index) in conditions[group]" :key="index" class="workflow-condition">
      <div class="workflow-row">
        <select v-model="condition.field" @change="change(condition)" :aria-label="group + ' condition field ' + (index + 1)"><option v-for="[value, label] in fields" :value="value" :key="value">{{ label.replace(' ID', '') }}</option></select>
        <select v-model="condition.operator" :aria-label="group + ' condition operator ' + (index + 1)"><option v-for="[value, label] in availableOperators(condition.field)" :key="value" :value="value">{{ label }}</option></select>
        <select v-if="choices(condition.field) && ['eq', 'neq'].includes(condition.operator)" v-model="condition.value" :aria-label="group + ' condition value ' + (index + 1)"><option v-for="[value, label] in choices(condition.field)" :key="value" :value="value">{{ label }}</option></select>
        <input v-else-if="!['empty', 'not_empty'].includes(condition.operator)" v-model="condition.value" :type="numeric(condition.field) || ['gte', 'lte'].includes(condition.operator) ? 'number' : 'text'" :min="numeric(condition.field) ? 0 : undefined" :step="condition.field.endsWith('_count') ? 1 : 'any'" :aria-label="group + ' condition value ' + (index + 1)" :placeholder="condition.field.endsWith('_minutes') ? 'Minutes' : 'Value'" required maxlength="255" />
        <button type="button" class="icon-button" @click="conditions[group].splice(index, 1)" aria-label="Remove condition"><Icon name="x" /></button>
      </div>
      <div v-if="condition.field === 'since_events_minutes'" class="condition-options">
        <label v-for="[value, label] in events" :key="value" class="check-label"><input type="checkbox" v-model="condition.events" :value="value" />{{ label }}</label>
        <small>Count from the most recent selected event. Events that have never happened are skipped; at least one must have happened.</small>
      </div>
      <label v-if="condition.field.startsWith('follow_up_')" class="condition-scope">By<select v-model="condition.scope" :aria-label="group + ' follow-up scope ' + (index + 1)"><option value="anyone">Anyone</option><option value="rule">This rule</option></select></label>
      <label v-if="textField(condition.field)" class="check-label"><input type="checkbox" v-model="condition.case_sensitive" />Case-sensitive matching</label>
      <small v-if="condition.field.endsWith('_minutes')" class="muted">Minutes: 1 hour = 60 · 4 hours = 240 · 1 day = 1,440 · 2 days = 2,880.</small>
      <small v-if="condition.field === 'follow_up_count'" class="muted">Only successfully sent follow-ups count. Queued, held, failed, and cancelled replies do not.</small>
      <small v-if="condition.field === 'follow_up_created_count'" class="muted">Includes pending, queued, held, failed, and sent follow-ups; excludes cancelled schedules. Use this to prevent duplicate attempts.</small>
    </div>
    <button type="button" class="text-button" @click="conditions[group].push({ field: 'status', operator: 'eq', value: 'Open' })" :disabled="conditions[group].length >= 20"><Icon name="plus" :size="15" />Add {{ group === 'all' ? 'required' : 'alternative' }} condition</button>
</div>
<p class="muted">Phrase matching respects word boundaries and flexible spaces. Text ignores case unless selected. Status-change timers start with new tickets or their next status change; earlier status history cannot be reconstructed.</p>
</template>
<style scoped>
.workflow-condition{display:grid;gap:9px;margin-bottom:16px}.workflow-condition .workflow-row{margin-bottom:0}.condition-options{display:flex;flex-wrap:wrap;gap:10px 18px}.condition-options small{flex-basis:100%;color:var(--muted)}.condition-scope{display:flex;align-items:center;gap:10px}.condition-scope select{max-width:200px}
</style>
