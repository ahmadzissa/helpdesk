<script setup>
import { reactive, ref, onMounted } from 'vue';
import { state, api, notify } from '../store';
import Modal from './Modal.vue';
const data = ref(null), creating = ref(false), saving = ref(false), token = ref(''), error = ref('');
const form = reactive({ name: '', mailbox_id: state.workspace.mailboxes[0]?.id ?? null, scopes: ['tickets:create'], expires_at: '' });
async function load() { try { data.value = await api('api-keys'); } catch (e) { error.value = e.message; } }
async function create() { saving.value = true; error.value = ''; try { const result = await api('api-keys', { method: 'POST', body: { ...form, expires_at: form.expires_at ? new Date(form.expires_at).toISOString() : null } }); token.value = result.token; creating.value = false; await load(); } catch (e) { error.value = e.message; } finally { saving.value = false; } }
async function revoke(key) { try { await api('api-keys/' + key.id, { method: 'DELETE' }); await load(); notify('API key revoked'); } catch (e) { notify(e.message, true); } }
async function copy() { try { await navigator.clipboard.writeText(token.value); notify('API key copied'); } catch { notify('Select and copy the key below.', true); } }
onMounted(load);
</script>
<template>
<p v-if="error && !creating" class="error-message">{{ error }}</p>
<template v-if="data"><div class="subsection-heading"><div><h2>Ticket creation API</h2><p class="muted">Create tickets from your application’s server with a key scoped to one mailbox.</p></div><button class="primary-button" @click="creating = true; error = ''"><Icon name="plus" />Create API key</button></div>
<div class="info-banner"><Icon name="lock" /><p>Keep API keys on your server. Keys are shown once, stored as hashes, and can be revoked immediately. Limit: 60 requests per minute per key.</p></div>
<div v-for="key in data.keys" :key="key.id" class="management-row"><Icon name="lock" /><div class="management-row-main"><h3>{{ key.name }} <span class="muted">{{ key.prefix }}…</span></h3><p>{{ key.scopes.join(', ') }} · {{ key.revoked_at ? 'Revoked' : key.expires_at && new Date(key.expires_at + 'Z') < new Date() ? 'Expired' : 'Active' }}</p><small class="muted">Last used: {{ key.last_used_at || 'Never' }}</small></div><button v-if="!key.revoked_at" class="text-button" @click="revoke(key)">Revoke key</button></div><p v-if="!data.keys.length" class="muted">No API keys yet.</p>
<section class="policy-section api-example"><h2>Create a ticket</h2><p>Send JSON to <code>POST {{ data.base_url }}/tickets</code>. Reuse the same Idempotency-Key when retrying the same request.</p><pre>Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
Idempotency-Key: your-app-request-123

{
  "subject": "Help with order 123",
  "requester_email": "customer@example.com",
  "requester_name": "Customer",
  "body": "Please check my order.",
  "priority": "Normal",
  "tags": ["app"],
  "custom_fields": { "order_id": "123" }
}</pre>
<p>A new ticket returns <code>201</code> with <code>ticket.id</code>, status, and a workspace URL. A retry returns <code>200</code> with the original ticket. Reusing a key with different details returns <code>409</code>. Invalid fields return <code>422</code>; invalid keys return <code>401</code>; missing scope returns <code>403</code>; rate limits return <code>429</code>.</p><p class="muted">The key selects the mailbox. Ticket-created rules can run automatically. Public registration does not affect API access.</p></section>
<section class="policy-section api-example"><h2>Report delivery events</h2><p>Keys with <code>delivery:write</code> can send normalized events to <code>POST {{ data.base_url }}/delivery-events</code>.</p><pre>{
  "event_id": "provider-event-123",
  "message_id": "original-message-id@your-domain.com",
  "type": "delivered",
  "recipient": "customer@example.com",
  "detail": "Accepted by receiving server"
}</pre><p>Supported types: <code>delivered</code>, <code>failed</code>, <code>opened</code>, <code>complaint</code>, <code>opt_out</code>. Use the outbound email’s Message-ID or provide <code>attempt_id</code> from delivery details. Optional <code>occurred_at</code> is an ISO timestamp. Duplicate event IDs are ignored; unknown mailbox/recipient combinations are rejected.</p><p class="muted">SMTP acceptance alone does not confirm delivery. Incoming delivery reports and these authenticated events provide confirmation. Complaint and opt-out events suppress the recipient.</p></section></template>
<Modal v-if="creating" title="Create API key" @close="!saving && (creating = false)"><form class="form-stack" @submit.prevent="create"><p v-if="error" class="error-message">{{ error }}</p><label>Key name<input v-model="form.name" required maxlength="100" placeholder="Our app production" /></label><label>Mailbox<select v-model="form.mailbox_id" required><option v-for="box in state.workspace.mailboxes" :key="box.id" :value="box.id">{{ box.name }}</option></select></label><label class="check-label"><input type="checkbox" v-model="form.scopes" value="tickets:create" />Create tickets</label><label class="check-label"><input type="checkbox" v-model="form.scopes" value="delivery:write" />Report delivery, complaint and opt-out events</label><label>Expires at (optional, local time)<input v-model="form.expires_at" type="datetime-local" /></label><div class="form-actions"><button class="primary-button" :disabled="saving || !form.scopes.length || !form.mailbox_id">Create API key</button></div></form></Modal>
<Modal v-if="token" title="Copy your API key" @close="token = ''"><div class="form-stack"><p>This key is only shown once. Store it in your application server’s secret configuration.</p><textarea :value="token" readonly rows="3" aria-label="New API key" /><button class="secondary-button" @click="copy"><Icon name="copy" />Copy key</button><button class="primary-button" @click="token = ''">I’ve saved the key</button></div></Modal>
</template>
