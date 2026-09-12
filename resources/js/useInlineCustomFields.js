import { reactive, watch, onBeforeUnmount } from 'vue';

export function useInlineCustomFields(getTicket, persist) {
    const values = reactive({}), errors = reactive({}), saving = reactive({});
    const dirty = new Set(), requests = new Map();
    let disposed = false;
    const current = key => getTicket()?.custom_fields?.[key] ?? '';
    watch(() => [getTicket()?.id, getTicket()?.custom_field_definitions, getTicket()?.custom_fields], () => {
        for (const field of getTicket()?.custom_field_definitions || []) {
            if (!dirty.has(field.key) && !saving[field.key]) values[field.key] = current(field.key);
        }
    }, { immediate: true, deep: true });
    function edit(key, value) { values[key] = value; dirty.add(key); delete errors[key]; }
    function reset(key) { if (!saving[key]) { values[key] = current(key); dirty.delete(key); delete errors[key]; } }
    function save(key) {
        if (requests.has(key)) return requests.get(key);
        if (disposed || !dirty.has(key) || getTicket()?.merged_into_id) return Promise.resolve();
        const id = getTicket().id, value = values[key];
        if (value === current(key)) { dirty.delete(key); return Promise.resolve(); }
        saving[key] = true; delete errors[key];
        const request = Promise.resolve().then(() => persist(key, value)).then(() => {
            if (!disposed && getTicket()?.id === id && values[key] === value) { dirty.delete(key); values[key] = current(key); }
        }).catch(error => { if (!disposed && getTicket()?.id === id) errors[key] = error.message; }).finally(() => {
            saving[key] = false; requests.delete(key);
        });
        requests.set(key, request);
        return request;
    }
    onBeforeUnmount(() => { disposed = true; });
    return { values, errors, saving, edit, save, reset };
}
