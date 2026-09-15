<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
defineProps({ title: String, wide: Boolean });
const emit = defineEmits(['close']);
const panel = ref();
const previous = document.activeElement;
function keydown(event) {
    if (event.key === 'Escape') { emit('close'); }
    if (event.key === 'Tab') {
        const elements = [...panel.value.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"], [contenteditable="true"]')].filter(el => !el.disabled && el.offsetParent !== null);
        const first = elements[0], last = elements.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
}
onMounted(async () => { document.addEventListener('keydown', keydown); await nextTick(); panel.value.querySelector('input, textarea, button')?.focus(); });
onBeforeUnmount(() => { document.removeEventListener('keydown', keydown); previous?.focus(); });
</script>
<template>
<Teleport to="body"><div class="modal-backdrop" @mousedown.self="emit('close')">
    <section ref="panel" class="modal" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
        <header><h2>{{ title }}</h2><button class="icon-button" @click="emit('close')" aria-label="Close dialog"><Icon name="x" /></button></header>
        <div class="modal-content"><slot /></div>
    </section>
</div></Teleport>
</template>

