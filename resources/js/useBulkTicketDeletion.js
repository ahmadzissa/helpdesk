import { ref, watch } from 'vue';
import { api } from './store.js';

export function useBulkTicketDeletion(selected, busy, onDeleted, request = api) {
    const deleting = ref(null), error = ref('');
    watch(selected, () => { if (!busy.value) deleting.value = null; });

    function open() {
        if (busy.value || !selected.value.length) return;
        error.value = '';
        deleting.value = [...selected.value];
    }

    async function confirm() {
        if (busy.value || !deleting.value?.length) return false;
        busy.value = true; error.value = '';
        try {
            const result = await request('tickets/bulk', { method: 'DELETE', body: { ids: [...deleting.value], confirmed: true } });
            deleting.value = null;
            await onDeleted(result);
            return true;
        } catch (exception) {
            error.value = exception.message;
            return false;
        } finally { busy.value = false; }
    }

    return { deleting, error, open, confirm };
}
