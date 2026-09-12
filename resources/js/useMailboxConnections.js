import { computed, onScopeDispose, ref, watch } from 'vue';
import { api } from './store.js';

const fields = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password'];

export function useMailboxConnections(form, modal, request = api) {
    const testing = ref(false), results = ref(null), token = ref(''), error = ref('');
    const original = ref(''), originallySending = ref(false), originallyIncoming = ref(false);
    const settings = () => Object.fromEntries(fields.map(field => [field, form[field] ?? '']));
    const snapshot = () => JSON.stringify(settings());
    let generation = 0, controller, expiryTimer;
    const required = computed(() => modal.value?.type === 'mailboxes' && (!modal.value.id || snapshot() !== original.value
        || (form.sending_enabled && !originallySending.value) || (form.incoming_enabled && !originallyIncoming.value)));
    const passed = computed(() => !!token.value && results.value?.smtp?.success === true && results.value?.imap?.success === true);
    const canSave = computed(() => !testing.value && (!required.value || passed.value));

    function clear() {
        generation++;
        controller?.abort();
        clearTimeout(expiryTimer);
        testing.value = false; token.value = ''; results.value = null; error.value = '';
    }
    watch(() => modal.value, () => {
        clear(); original.value = snapshot();
        originallySending.value = !!form.sending_enabled; originallyIncoming.value = !!form.incoming_enabled;
    }, { immediate: true, flush: 'sync' });
    watch(snapshot, clear, { flush: 'sync' });

    async function test() {
        if (testing.value) return;
        clear();
        const current = generation, body = settings(), id = modal.value?.id;
        controller = new AbortController();
        testing.value = true;
        try {
            const result = await request('mailboxes/' + (id ? id + '/' : '') + 'test-connection', { method: 'POST', body, signal: controller.signal });
            if (current !== generation) return;
            results.value = result;
            const remaining = Date.parse(result.expires_at) - Date.now();
            if (result.smtp?.success && result.imap?.success && result.token && remaining > 0) {
                token.value = result.token;
                expiryTimer = setTimeout(() => { token.value = ''; error.value = 'Connection test results expired. Test both connections again before saving.'; }, remaining);
            }
        } catch (e) {
            if (current === generation && e.name !== 'AbortError') error.value = e.message;
        } finally { if (current === generation) testing.value = false; }
    }
    onScopeDispose(clear);
    return { testing, results, token, error, required, passed, canSave, test };
}
