<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../store';
import Modal from './Modal.vue';
const props = defineProps({ message: { type: Object, required: true } });
const emit = defineEmits(['close']);
const data = ref(null), error = ref('');
const when = date => date ? new Date(date.includes('T') || date.endsWith('Z') ? date : date + 'Z').toLocaleString() : 'Not confirmed';
onMounted(async () => { try { data.value = await api('messages/' + props.message.id + '/delivery'); } catch (e) { error.value = e.message; } });
</script>
<template>
<Modal title="Message status" wide @close="emit('close')"><p v-if="error" class="error-message">{{ error }}</p><div v-else-if="data" class="form-stack"><h3>Current state: {{ data.delivery }}</h3><p v-if="data.error" class="error-message">{{ data.error }}</p><p class="muted">Sent means the SMTP server accepted the message. Delivered requires confirmation. An email-open event may come from a privacy service and does not confirm that a person read the message.</p>
<article v-for="attempt in data.attempts" :key="attempt.id" class="delivery-attempt"><h3>Attempt · {{ when(attempt.created_at) }}</h3><dl><dt>Recipients</dt><dd>{{ attempt.recipients.join(', ') }}</dd><dt>SMTP accepted</dt><dd>{{ when(attempt.sent_at) }}</dd><dt>All recipients delivered</dt><dd>{{ when(attempt.delivered_at) }}</dd><dt>First email open</dt><dd>{{ when(attempt.opened_at) }}</dd><dt>Failure</dt><dd>{{ attempt.failed_at ? when(attempt.failed_at) + ' · ' + attempt.error : 'None reported' }}</dd><dt>Message-ID</dt><dd><code>{{ attempt.external_id }}</code></dd><dt>Attempt ID</dt><dd><code>{{ attempt.id }}</code></dd></dl>
<div v-for="event in attempt.events" :key="event.id" class="delivery-event"><strong>{{ event.type === 'opened' ? 'Email opened' : event.type.replaceAll('_', ' ') }}</strong> · {{ event.recipient || 'Message status tracking' }}<small>{{ when(event.occurred_at) }}{{ event.type === 'opened' ? ' · Email open detected; this may be an email privacy service.' : event.detail ? ' · ' + event.detail : '' }}</small></div></article><p v-if="!data.attempts.length" class="muted">This message has not made an outgoing delivery attempt.</p></div><p v-else class="muted">Loading message status…</p></Modal>
</template>
