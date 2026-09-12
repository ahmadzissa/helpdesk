<script setup>
import { useInlineCustomFields } from '../useInlineCustomFields';
const props = defineProps({ ticket: { type: Object, required: true }, saveField: { type: Function, required: true } });
const { values, errors, saving, edit, save, reset } = useInlineCustomFields(() => props.ticket, (key, value) => props.saveField(key, value));
</script>

<template>
    <section v-if="ticket.custom_field_definitions?.length" class="inspector-section ticket-custom-fields" aria-label="Custom ticket details">
        <div v-for="field in ticket.custom_field_definitions" :key="field.key" class="inline-ticket-field">
            <label :for="'ticket-field-' + field.key">{{ field.name }}</label>
            <input :id="'ticket-field-' + field.key" :value="values[field.key] ?? ''" :placeholder="'Enter ' + field.name.toLowerCase()" maxlength="500" :disabled="Boolean(ticket.merged_into_id) || saving[field.key]" :aria-invalid="Boolean(errors[field.key])" :aria-describedby="errors[field.key] ? 'field-error-' + field.key : undefined" @input="edit(field.key, $event.target.value)" @blur="save(field.key)" @keydown.enter.prevent="$event.target.blur()" @keydown.esc.prevent="reset(field.key); $event.target.blur()" />
            <small v-if="saving[field.key]" role="status">Saving…</small>
            <div v-if="errors[field.key]" :id="'field-error-' + field.key" class="error-message" role="alert">{{ errors[field.key] }} <button type="button" class="text-button" @click="save(field.key)">Retry</button></div>
        </div>
    </section>
</template>

<style scoped>
.ticket-custom-fields{display:grid;gap:16px}.inline-ticket-field{display:grid;gap:7px;min-width:0}.inline-ticket-field label{font-size:11px;font-weight:500;overflow-wrap:anywhere}.inline-ticket-field input{width:100%;min-width:0;font-size:12px;padding:9px 10px;background:var(--bg);border:1px solid var(--line);border-radius:6px}.inline-ticket-field small{font-size:10px;color:var(--muted)}.inline-ticket-field .error-message{font-size:11px;line-height:1.5}
</style>
