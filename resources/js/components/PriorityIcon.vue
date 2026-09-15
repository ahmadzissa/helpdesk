<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
const props = defineProps({ priority: { type: String, default: 'Normal' } });
const level = computed(() => ['Low', 'Normal', 'High', 'Urgent'].includes(props.priority) ? props.priority : 'Normal');
const icon = computed(() => ({ Low: 'priority-down', Normal: 'circle', High: 'priority-up', Urgent: 'priority-urgent' })[level.value]);
</script>
<template>
<span class="priority-indicator" :class="level.toLowerCase()" role="img" :aria-label="level + ' priority'" :title="level + ' priority'"><Icon :name="icon" :size="level === 'Normal' ? 7 : 15" /></span>
</template>
<style scoped>
.priority-indicator{display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;width:16px;flex-shrink:0;margin-inline-end:7px;line-height:1}.priority-indicator.normal{color:#8b8b96}.priority-indicator.normal :deep(svg){fill:currentColor;stroke:none}.priority-indicator.low{color:#2563eb}.priority-indicator.high,.priority-indicator.urgent{color:#dc2626}
</style>
