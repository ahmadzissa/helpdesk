<script setup>
import { ref, computed } from 'vue';
import { languages, languageName } from '../translation';
const props = defineProps({ modelValue: { default: '' }, label: { default: 'Language' }, automatic: Boolean, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const custom = ref(false);
const known = computed(() => languages.some(([code]) => code === props.modelValue));
function select(value) { if (value === '_custom') custom.value = true; else { custom.value = false; emit('update:modelValue', value || null); } }
</script>
<template>
<div class="language-picker"><select :value="custom ? '_custom' : modelValue || ''" :aria-label="label" :disabled="disabled" @change="select($event.target.value)"><option v-if="automatic" value="">Detect automatically</option><option v-else value="" disabled>Select a language</option><option v-for="[code, name] in languages" :key="code" :value="code">{{ name }}</option><option v-if="modelValue && !known" :value="modelValue">{{ languageName(modelValue) }}</option><option value="_custom">Other Google language code…</option></select><input v-if="custom" :value="modelValue" :aria-label="label + ' code'" placeholder="Language code, e.g. af" pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}" maxlength="35" :disabled="disabled" @change="emit('update:modelValue', $event.target.value)" /></div>
</template>
