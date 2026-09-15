<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import Icon from './Icon.vue';
import PriorityIcon from './PriorityIcon.vue';
import { initials, statusClass } from '../store';

const props = defineProps({ modelValue: { default: null }, options: { type: Array, required: true }, label: { type: String, required: true }, placeholder: { default: 'Choose an option' }, kind: { default: '' }, searchable: Boolean, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const trigger = ref(), menu = ref(), searchInput = ref(), opened = ref(false), query = ref(''), position = ref({});
const menuId = useId();
const selected = computed(() => props.options.find(option => option.value === props.modelValue));
const filtered = computed(() => props.options.filter(option => `${option.label} ${option.description || ''}`.toLowerCase().includes(query.value.trim().toLowerCase())));

function close(restoreFocus = false) {
    opened.value = false;
    if (restoreFocus) trigger.value?.focus();
}
async function toggle() {
    if (props.disabled) return;
    if (opened.value) { close(true); return; }
    query.value = '';
    opened.value = true;
    position.value = { visibility: 'hidden' };
    await nextTick();
    if (!opened.value || !menu.value || !trigger.value) return;
    const anchor = trigger.value.getBoundingClientRect(), popup = menu.value.getBoundingClientRect();
    const width = document.documentElement.clientWidth, height = document.documentElement.clientHeight;
    const top = anchor.bottom + popup.height + 8 <= height - 12 ? anchor.bottom + 6 : anchor.top - popup.height - 6;
    position.value = { top: Math.max(12, Math.min(top, height - popup.height - 12)) + 'px', left: Math.max(12, Math.min(anchor.right - popup.width, width - popup.width - 12)) + 'px' };
    await nextTick();
    if (props.searchable) searchInput.value?.focus();
    else (menu.value?.querySelector('[aria-checked="true"]') || menu.value?.querySelector('[role="menuitemradio"]'))?.focus();
}
function choose(option) {
    if (props.disabled) return;
    close(true);
    if (option.value !== props.modelValue) emit('update:modelValue', option.value);
}
function keydown(event) {
    if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
    if (event.key === 'Tab') { close(true); return; }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    if (event.target === searchInput.value && ['Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const items = [...(menu.value?.querySelectorAll('[role="menuitemradio"]') || [])];
    const index = items.indexOf(document.activeElement);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : index < 0 ? (event.key === 'ArrowUp' ? items.length - 1 : 0) : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[next]?.focus();
}
watch(opened, (value, previous, onCleanup) => {
    if (!value) return;
    const outside = event => { if (!menu.value?.contains(event.target) && !trigger.value?.contains(event.target)) close(); };
    const scroll = event => { if (!menu.value?.contains(event.target)) close(); };
    const resize = () => close();
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', keydown);
    document.addEventListener('scroll', scroll, true);
    window.addEventListener('resize', resize);
    onCleanup(() => {
        document.removeEventListener('pointerdown', outside);
        document.removeEventListener('keydown', keydown);
        document.removeEventListener('scroll', scroll, true);
        window.removeEventListener('resize', resize);
    });
});
watch(() => props.disabled, value => { if (value) close(); });
watch(() => props.modelValue, () => close());
</script>

<template>
    <button ref="trigger" type="button" class="inspector-select" :class="{ 'is-open': opened, 'is-empty': !modelValue }" :disabled="disabled" :aria-label="label + ': ' + (selected?.label || placeholder)" aria-haspopup="dialog" :aria-expanded="opened" :aria-controls="opened ? menuId : undefined" @click="toggle" @keydown.down.prevent="!opened && toggle()">
        <span v-if="kind === 'status'" class="inspector-status-dot" :class="statusClass(modelValue)" />
        <PriorityIcon v-else-if="kind === 'priority'" :priority="modelValue" />
        <span v-else-if="kind === 'agent' && modelValue" class="inspector-option-avatar">{{ initials(selected?.label) }}</span>
        <Icon v-else-if="kind === 'agent' || kind === 'team'" :name="kind === 'team' ? 'users' : 'user'" :size="15" />
        <span class="inspector-select-value">{{ selected?.label || placeholder }}</span><Icon name="down" :size="13" />
    </button>
    <Teleport to="body">
        <div v-if="opened" :id="menuId" ref="menu" class="inspector-option-menu" :style="position" role="dialog" :aria-label="label">
            <div class="inspector-option-heading">{{ label }}</div>
            <label v-if="searchable" class="inspector-option-search"><Icon name="search" :size="15" /><input ref="searchInput" v-model="query" :aria-label="'Search ' + label.toLowerCase()" placeholder="Search by name…" autocomplete="off" /></label>
            <div class="inspector-option-list" role="menu" :aria-label="label + ' options'">
                <button v-for="option in filtered" :key="String(option.value)" type="button" role="menuitemradio" :aria-checked="option.value === modelValue" @click="choose(option)">
                    <span v-if="kind === 'status'" class="inspector-status-dot" :class="statusClass(option.value)" />
                    <PriorityIcon v-else-if="kind === 'priority'" :priority="option.value" aria-hidden="true" />
                    <span v-else-if="kind === 'agent' && option.value" class="inspector-option-avatar" aria-hidden="true">{{ initials(option.label) }}</span>
                    <Icon v-else-if="kind === 'agent' || kind === 'team'" :name="kind === 'team' ? 'users' : 'user'" :size="16" />
                    <span class="inspector-option-copy"><span>{{ option.label }}</span><small v-if="option.description">{{ option.description }}</small></span>
                    <Icon v-if="option.value === modelValue" name="check" class="inspector-option-check" :size="16" />
                </button>
            </div>
            <p v-if="!filtered.length" class="inspector-option-empty" role="status">No matches found.</p>
        </div>
    </Teleport>
</template>

<style>
.inspector-select{display:inline-flex;align-items:center;gap:7px;min-height:32px;max-width:100%;padding:5px 9px;border:1px solid var(--line);border-radius:7px;background:var(--surface);color:var(--text);font-size:12px;text-align:start;cursor:pointer}
.inspector-select:hover,.inspector-select.is-open{border-color:var(--accent);background:var(--soft)}
.inspector-select:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.inspector-select:disabled{opacity:.55;cursor:default}.inspector-select>svg{flex-shrink:0;color:var(--muted)}.inspector-select>svg:last-child{margin-left:auto}
.inspector-select-value{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}.inspector-select.is-empty{color:var(--muted)}
.inspector-status-dot{display:inline-block;flex:0 0 8px;width:8px;height:8px;border-radius:50%;background:currentColor}.inspector-status-dot.open{color:var(--status-open)}.inspector-status-dot.pending{color:var(--status-pending)}.inspector-status-dot.on-hold{color:var(--status-on-hold)}.inspector-status-dot.solved{color:var(--status-solved)}.inspector-status-dot.closed{color:var(--status-closed)}
.inspector-option-menu{position:fixed;z-index:60;width:280px;max-width:calc(100vw - 24px);max-height:calc(100dvh - 24px);overflow:auto;padding:6px;border:1px solid var(--line);border-radius:10px;background:var(--surface);color:var(--text);box-shadow:0 10px 35px #0002;font-size:12px}
.inspector-option-heading{padding:9px 10px;font-size:11px;font-weight:600;color:var(--muted)}
.inspector-option-search{display:flex;flex-direction:row;align-items:center;gap:7px;margin:0 4px 6px;padding:0 9px;border:1px solid var(--line);border-radius:6px;color:var(--muted)}.inspector-option-search:focus-within{border-color:var(--accent)}.inspector-option-search input{min-width:0;width:100%;border:0;padding:9px 0;box-shadow:none;background:transparent;font-size:12px}
.inspector-option-list{max-height:260px;overflow:auto;overscroll-behavior:contain}.inspector-option-list>button{display:flex;align-items:center;gap:10px;width:100%;min-height:39px;padding:9px 10px;border-radius:6px;text-align:start;font-size:12px;color:var(--text)}
.inspector-option-list>button:hover,.inspector-option-list>button[aria-checked="true"]{background:var(--soft)}.inspector-option-list>button:focus-visible{outline:2px solid var(--accent);outline-offset:-2px;background:var(--soft)}
.inspector-option-copy{display:grid;gap:3px;flex:1;min-width:0;overflow-wrap:anywhere}.inspector-option-copy small{font-size:11px;line-height:1.4;color:var(--muted)}.inspector-option-check{color:var(--accent-ink);flex-shrink:0}.inspector-option-avatar{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:23px;height:23px;border-radius:6px;background:var(--selected);color:var(--accent-ink);font-size:10px;font-weight:600}.inspector-option-empty{padding:14px 10px;color:var(--muted)}
.inspector-select .priority-indicator,.inspector-option-list .priority-indicator{margin:0}
</style>
