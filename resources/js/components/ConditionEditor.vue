<script setup>
import { state } from '../store';
const conditions = defineModel({ type: Object, required: true });
const fields = [['status', 'Status'], ['priority', 'Priority'], ['folder', 'Folder'], ['source', 'Source'], ['assignee_id', 'Agent ID'], ['team_id', 'Team ID'], ['mailbox_id', 'Mailbox ID'], ['subject', 'Subject'], ['requester_email', 'Requester email'], ['requester_domain', 'Requester domain'], ['company', 'Company'], ['tag', 'Tag'], ['body', 'Latest customer message'], ['sender_trust', 'Sender trust'], ['age_minutes', 'Age in minutes'], ['idle_minutes', 'Inactive for minutes'], ['since_customer_minutes', 'Minutes since customer message'], ['since_reply_minutes', 'Minutes since reply']];
const operators = [['eq', 'equals'], ['neq', 'does not equal'], ['contains', 'contains'], ['not_contains', 'does not contain'], ['starts_with', 'starts with'], ['ends_with', 'ends with'], ['empty', 'is empty'], ['not_empty', 'is not empty'], ['gte', 'is at least'], ['lte', 'is at most']];
const choices = field => ({
    status: state.workspace.statuses.map(v => [v, v]), priority: state.workspace.priorities.map(v => [v, v]),
    folder: ['inbox', 'archive', 'spam', 'trash'].map(v => [v, v]), source: ['Email', 'Web', 'Webhook', 'Phone'].map(v => [v, v]),
    sender_trust: ['trusted', 'blocked', 'neutral'].map(v => [v, v]),
    assignee_id: [['unassigned', 'Unassigned'], ...state.workspace.agents.map(v => [String(v.id), v.name])],
    team_id: state.workspace.teams.map(v => [String(v.id), v.name]),
    mailbox_id: state.workspace.mailboxes.map(v => [String(v.id), v.name]),
}[field]);
function change(condition) { condition.value = choices(condition.field)?.[0]?.[0] || ''; condition.operator = condition.field.endsWith('_minutes') ? 'gte' : 'eq'; }
</script>
<template>
<div v-for="group in ['all', 'any']" :key="group" class="condition-group">
    <h3>{{ group === 'all' ? 'Match all of these' : 'Also match any of these' }}</h3>
    <p class="muted">{{ group === 'all' ? 'Every condition in this group must match.' : 'At least one condition must match. Leave empty to skip this group.' }}</p>
    <div v-for="(condition, index) in conditions[group]" :key="index" class="workflow-row">
        <select v-model="condition.field" @change="change(condition)" :aria-label="group + ' condition field ' + (index + 1)"><option v-for="[value, label] in fields" :value="value" :key="value">{{ label.replace(' ID', '') }}</option></select>
        <select v-model="condition.operator" :aria-label="group + ' condition operator ' + (index + 1)"><option v-for="[value, label] in operators" :key="value" :value="value">{{ label }}</option></select>
        <select v-if="choices(condition.field) && ['eq', 'neq'].includes(condition.operator)" v-model="condition.value" :aria-label="group + ' condition value ' + (index + 1)"><option v-for="[value, label] in choices(condition.field)" :key="value" :value="value">{{ label }}</option></select>
        <input v-else-if="!['empty', 'not_empty'].includes(condition.operator)" v-model="condition.value" :type="['gte', 'lte'].includes(condition.operator) ? 'number' : 'text'" :aria-label="group + ' condition value ' + (index + 1)" placeholder="Value" required maxlength="255" />
        <button type="button" class="icon-button" @click="conditions[group].splice(index, 1)" aria-label="Remove condition"><Icon name="x" /></button>
    </div>
    <button type="button" class="text-button" @click="conditions[group].push({ field: 'status', operator: 'eq', value: 'Open' })" :disabled="conditions[group].length >= 20"><Icon name="plus" :size="15" />Add {{ group === 'all' ? 'required' : 'alternative' }} condition</button>
</div>
<p class="muted">Values are case insensitive. Use exact status names, “unassigned” for an agent with no assignment, and trusted / blocked / neutral for sender trust.</p>
</template>
