<script setup>
import { computed, ref, reactive, onMounted, watch } from 'vue';
import { state, api, notify } from '../store';
const admin = computed(() => state.user?.role === 'admin');
const safety = computed(() => state.workspace.sending_safety);
const limits = reactive({ threshold: safety.value?.threshold ?? 5, window_minutes: safety.value?.window_minutes ?? 15 });
const busy = ref(false), error = ref(''), failures = ref([]), held = ref([]), review = ref(''), reviewed = ref(false);
async function refresh() {
    try {
        const result = await api('sending-safety?review=1');
        state.workspace.sending_safety = result;
        failures.value = result.failures || []; held.value = result.held || [];
    } catch (e) { error.value = e.message; }
}
async function change(action) {
    busy.value = true; error.value = '';
    try {
        const body = action === 'resume' ? { review: review.value, reviewed: reviewed.value, epoch: safety.value.epoch } : action === 'configure' ? limits : {};
        state.workspace.sending_safety = await api('sending-safety' + (action === 'configure' ? '' : '/' + action), { method: action === 'configure' ? 'PUT' : 'POST', body });
        reviewed.value = false; review.value = ''; state.refresh++;
        await refresh();
        notify(action === 'resume' ? 'Sending reactivated. Held replies still need individual review.' : action === 'pause' ? 'All outgoing email paused. Incoming mail continues.' : 'Bounce protection settings saved.');
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
watch([() => safety.value?.paused, () => safety.value?.epoch], () => { reviewed.value = false; review.value = ''; refresh(); });
onMounted(refresh);
</script>
<template>
<section v-if="safety" class="sending-safety" :class="{ paused: safety.paused }" aria-label="Bounce protection">
    <div class="safety-heading"><span class="setting-icon"><Icon :name="safety.paused ? 'alert' : 'check'" :size="22" /></span><div><h2>{{ safety.paused ? 'Receive-only mode' : 'Bounce protection is active' }}</h2><p>{{ safety.paused ? 'All outgoing email is paused. Incoming mail keeps arriving through enabled accounts.' : 'Automatically stop outgoing email across every account when delivery failures reach your limit.' }}</p></div><button class="icon-button" @click="refresh" aria-label="Refresh bounce protection"><Icon name="refresh" :size="17" /></button></div>
    <p v-if="safety.reason" class="safety-reason" role="status">{{ safety.reason }}</p>
    <div class="safety-metrics"><span><strong>{{ safety.recent_failures }} / {{ safety.threshold }}</strong> failures in {{ safety.window_minutes }} minutes</span><span><strong>{{ safety.held_count }}</strong> replies held for review</span></div>
    <p class="form-description">Counts failed sending attempts and confirmed permanent bounces. Repeat reports count once per attempt. A pause stays in place until an administrator reviews and reactivates sending.</p>
    <p v-if="error" class="error-message" role="alert">{{ error }}</p>
    <template v-if="admin">
        <form class="safety-limits" @submit.prevent="change('configure')"><label>Failure limit<input v-model.number="limits.threshold" type="number" min="1" max="1000" required /></label><label>Time window (minutes)<input v-model.number="limits.window_minutes" type="number" min="1" max="1440" required /></label><button class="secondary-button" :disabled="busy">Save protection settings</button></form>
        <div class="safety-review"><div class="subsection-heading"><h3>Recent delivery failures</h3><span class="muted">Latest 30</span></div><p v-if="!failures.length" class="muted">No recorded bounces or delivery failures.</p><article v-for="failure in failures" :key="failure.id" class="safety-failure"><div><strong>{{ failure.recipient }}</strong><small>{{ failure.mailbox_name || 'Removed account' }} · {{ new Date(failure.failed_at).toLocaleString() }}</small><p>{{ failure.error }}</p></div><Link v-if="failure.ticket_id" class="text-button" :href="$appUrl('/tickets/' + failure.ticket_id)">Review #{{ failure.ticket_id }}<Icon name="right" :size="14" /></Link></article></div>
        <div v-if="held.length" class="safety-review"><div class="subsection-heading"><h3>Held replies</h3><Link class="text-button" :href="$appUrl('/tickets?view=undelivered')">View undelivered</Link></div><p class="form-description">Reactivation does not send these automatically. Open each ticket to review and release its reply. Showing the first {{ held.length }}.</p><article v-for="message in held" :key="message.id" class="safety-failure"><div><strong>{{ message.subject }}</strong><small>{{ message.requester_email }}</small></div><Link class="text-button" :href="$appUrl('/tickets/' + message.ticket_id)">Review #{{ message.ticket_id }}</Link></article></div>
        <form v-if="safety.paused" class="safety-resume form-stack" @submit.prevent="change('resume')"><h3>Reactivate after review</h3><label>Review notes<textarea v-model="review" required minlength="5" maxlength="180" rows="2" placeholder="What caused the failures, and what did you fix?" /></label><label class="check-label"><input v-model="reviewed" type="checkbox" required />I reviewed the failures and account settings. Sending can resume.</label><div><button class="primary-button" :disabled="busy || !reviewed || review.trim().length < 5"><Icon name="send" :size="15" />Reactivate sending</button></div></form>
        <button v-else type="button" class="secondary-button" :disabled="busy" @click="change('pause')"><Icon name="pause" :size="15" />Pause all sending now</button>
    </template>
</section>
</template>
