<script setup>
import { ref } from 'vue';
defineProps({ title: { type: String, required: true } });
const panel = ref(), open = ref(true);
function reveal() { open.value = true; panel.value.open = true; panel.value.scrollIntoView({ block: 'nearest' }); }
defineExpose({ reveal });
</script>
<template>
<details ref="panel" class="inspector-section inspector-disclosure" :open="open" @toggle="open = $event.target.open">
    <summary><h3>{{ title }}</h3><Icon name="down" :size="15" /></summary>
    <div class="inspector-section-content"><slot /></div>
</details>
</template>
<style>
.inspector-disclosure>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;list-style:none}
.inspector-disclosure>summary::-webkit-details-marker{display:none}
.inspector-disclosure>summary h3{margin:0;font-size:12px}
.inspector-disclosure>summary>svg{flex-shrink:0;transition:transform .15s}
.inspector-disclosure:not([open])>summary>svg{transform:rotate(-90deg)}
.inspector-disclosure[open]>.inspector-section-content{margin-top:16px}
.inspector-section-content{min-width:0}
.inspector-disclosure>summary:focus-visible{outline:2px solid var(--accent);outline-offset:5px;border-radius:3px}
@media(prefers-reduced-motion:reduce){.inspector-disclosure>summary>svg{transition:none}}
</style>
