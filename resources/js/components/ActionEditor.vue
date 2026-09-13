<script setup>
import { state } from '../store';
const actions = defineModel({ type: Array, required: true });
const types = [['send_message', 'Send message'], ['send_follow_up', 'Send follow-up now'], ['status', 'Set status'], ['priority', 'Set priority'], ['folder', 'Move to folder'], ['assignee_id', 'Assign agent'], ['team_id', 'Assign team'], ['add_tag', 'Add tag'], ['remove_tag', 'Remove tag'], ['reply_id', 'Send canned response'], ['note', 'Add private note'], ['follow_up', 'Schedule follow-up']];
const messageAction = type => ['note', 'send_message', 'send_follow_up'].includes(type);
const options = type => ({
    status: state.workspace.statuses.map(v => [v, v]), priority: state.workspace.priorities.map(v => [v, v]),
    folder: ['inbox', 'archive', 'spam', 'trash'].map(v => [v, v]),
    assignee_id: [[null, 'Unassigned'], ...state.workspace.agents.map(v => [v.id, v.name])],
    team_id: [[null, 'No team'], ...state.workspace.teams.map(v => [v.id, v.name])],
    reply_id: state.workspace.replies.map(v => [v.id, v.title]),
}[type]);
function change(action) { action.value = options(action.type)?.[0]?.[0] ?? (action.type === 'follow_up' ? 60 : ''); delete action.body; if (action.type === 'follow_up') action.body = ''; }
function move(index, direction) { const next = [...actions.value]; [next[index], next[index + direction]] = [next[index + direction], next[index]]; actions.value = next; }
</script>
<template>
<div class="workflow-actions">
    <div v-for="(action, index) in actions" :key="index" class="workflow-action">
        <div class="workflow-row"><span class="step-number">{{ index + 1 }}</span><select v-model="action.type" @change="change(action)" :aria-label="'Action ' + (index + 1)"><option v-for="[value, label] in types" :key="value" :value="value">{{ label }}</option></select>
            <select v-if="options(action.type)" v-model="action.value" :aria-label="'Value for action ' + (index + 1)"><option v-for="[value, label] in options(action.type)" :key="String(value)" :value="value">{{ label }}</option></select>
            <input v-else-if="action.type === 'follow_up'" v-model.number="action.value" type="number" min="1" max="525600" aria-label="Follow-up delay in minutes" required />
            <input v-else-if="!messageAction(action.type)" v-model="action.value" :aria-label="'Value for action ' + (index + 1)" placeholder="Tag" maxlength="60" required />
            <button type="button" class="icon-button" @click="move(index, -1)" :disabled="index === 0" aria-label="Move action up">↑</button><button type="button" class="icon-button" @click="move(index, 1)" :disabled="index === actions.length - 1" aria-label="Move action down">↓</button><button type="button" class="icon-button" @click="actions.splice(index, 1)" aria-label="Remove action"><Icon name="x" /></button>
        </div>
        <template v-if="messageAction(action.type)"><textarea v-model="action.value" :placeholder="action.type === 'note' ? 'Private note' : 'Message to requester'" :aria-label="'Message text for action ' + (index + 1)" rows="5" required maxlength="20000" /><small v-pre>Variables: {{name}}, {{email}}, {{ticket_id}}, {{subject}}. Markdown is supported.</small></template>
        <template v-if="action.type === 'follow_up'"><small class="muted">Delay in minutes. Cancels if the customer replies before it runs.</small><textarea v-model="action.body" placeholder="Follow-up message" aria-label="Follow-up message" rows="3" required maxlength="20000" /></template>
    </div>
    <button type="button" class="text-button" @click="actions.push({ type: 'status', value: 'Open' })" :disabled="actions.length >= 20"><Icon name="plus" :size="15" />Add another action</button>
</div>
</template>
