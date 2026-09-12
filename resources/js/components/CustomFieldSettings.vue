<script setup>
import { ref, onMounted } from 'vue';
import { api, state, notify } from '../store';

const fields = ref([]), revision = ref(0), loading = ref(true), saving = ref(false), error = ref('');
async function load() {
    loading.value = true; error.value = '';
    try { const data = await api('custom-fields'); fields.value = data.fields; revision.value = data.revision; }
    catch (e) { error.value = e.message; } finally { loading.value = false; }
}
function add() { fields.value.push({ key: crypto.randomUUID(), name: '' }); }
async function save() {
    saving.value = true; error.value = '';
    try {
        const result = await api('custom-fields', { method: 'PUT', body: { revision: revision.value, fields: fields.value } });
        fields.value = result.fields; revision.value = result.revision;
        state.workspace.settings.custom_fields = result; state.refresh++;
        notify('Custom fields saved for all tickets');
    } catch (e) { error.value = e.message; } finally { saving.value = false; }
}
onMounted(load);
</script>

<template>
    <p v-if="loading" class="muted">Loading custom fields…</p>
    <form v-else class="settings-form" @submit.prevent="save">
        <div class="section-intro"><span class="setting-icon"><Icon name="file" /></span><div><h2>Custom fields</h2><p>Add the details you want to track on every ticket.</p></div></div>
        <p class="muted">Each field appears as a label and text input in the ticket’s right panel. Click an input to edit its value. Renaming a field keeps its saved values.</p>
        <fieldset class="custom-field-definitions" :disabled="saving">
            <div v-for="(field, index) in fields" :key="field.key" class="custom-field-definition">
                <label>Field name<input v-model="field.name" :aria-label="'Field name ' + (index + 1)" placeholder="e.g. Order number" required maxlength="80" /></label>
                <button type="button" class="icon-button" :aria-label="'Remove field ' + (field.name || index + 1)" @click="fields.splice(index, 1)"><Icon name="trash" :size="17" /></button>
            </div>
            <p v-if="!fields.length" class="muted">Add your first field to show it on existing and new tickets.</p>
            <button type="button" class="secondary-button" :disabled="fields.length >= 20" @click="add"><Icon name="plus" :size="16" />Add field</button>
        </fieldset>
        <p class="muted">Up to 20 fields. Removing a field hides it from tickets; existing stored values are kept.</p>
        <p v-if="error" class="error-message" role="alert">{{ error }}</p>
        <div class="form-actions"><button v-if="error" type="button" class="secondary-button" :disabled="saving" @click="load">Reload fields</button><button class="primary-button" :disabled="saving">{{ saving ? 'Saving…' : 'Save custom fields' }}</button></div>
    </form>
</template>

<style scoped>
.custom-field-definitions{border:0;padding:0;margin:0;display:grid;gap:16px;min-width:0}
.custom-field-definition{display:flex;align-items:flex-end;gap:12px}.custom-field-definition label{flex:1;min-width:0}.custom-field-definition .icon-button{margin-bottom:4px}
.custom-field-definitions>.secondary-button{justify-self:start}
</style>
