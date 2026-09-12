<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { state } from '../store';

const props = defineProps({ ticket: { type: Object, required: true }, disabled: Boolean });
const emit = defineEmits(['action']);
const trigger = ref(null), menu = ref(null), opened = ref(false), position = ref({});
const menuId = computed(() => 'ticket-options-' + props.ticket.id);
const priorities = computed(() => [...(state.workspace.priorities || [])].reverse());
const folders = computed(() => ['inbox', 'archive', 'spam', 'trash'].filter(folder => folder !== props.ticket.folder));
const priorityIcon = priority => ({ Urgent: 'priority-urgent', High: 'priority-up', Normal: 'circle', Low: 'priority-down' }[priority] || 'flag');
const folderLabel = folder => folder.charAt(0).toUpperCase() + folder.slice(1);

function close(restoreFocus = false) {
    opened.value = false;
    if (restoreFocus) trigger.value?.focus();
}
async function toggle() {
    if (props.disabled) return;
    if (opened.value) { close(true); return; }
    opened.value = true;
    position.value = { visibility: 'hidden' };
    await nextTick();
    if (!opened.value || !menu.value || !trigger.value) return;
    const anchor = trigger.value.getBoundingClientRect(), popup = menu.value.getBoundingClientRect();
    const margin = 12, gap = 6, viewportHeight = document.documentElement.clientHeight, viewportWidth = document.documentElement.clientWidth;
    const below = anchor.bottom + gap;
    const preferredTop = below + popup.height <= viewportHeight - margin ? below : anchor.top - gap - popup.height;
    position.value = {
        top: Math.max(margin, Math.min(preferredTop, viewportHeight - popup.height - margin)) + 'px',
        left: Math.max(margin, Math.min(anchor.right - popup.width, viewportWidth - popup.width - margin)) + 'px',
    };
    await nextTick();
    menu.value?.querySelector('button')?.focus();
}
function choose(changes) {
    if (props.disabled) return;
    close(true);
    if (Object.entries(changes).some(([key, value]) => props.ticket[key] !== value)) emit('action', changes);
}
function outsideClick(event) {
    if (!menu.value?.contains(event.target) && !trigger.value?.contains(event.target)) close();
}
function outsideScroll(event) {
    if (!menu.value?.contains(event.target)) close();
}
function keydown(event) {
    if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
    if (event.key === 'Tab') { close(true); return; }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const items = [...(menu.value?.querySelectorAll('button:not(:disabled)') || [])];
    const index = items.indexOf(document.activeElement);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[next]?.focus();
}
watch(opened, (value, previous, onCleanup) => {
    if (!value) return;
    const resized = () => close();
    document.addEventListener('pointerdown', outsideClick);
    document.addEventListener('keydown', keydown);
    document.addEventListener('scroll', outsideScroll, true);
    window.addEventListener('resize', resized);
    onCleanup(() => {
        document.removeEventListener('pointerdown', outsideClick);
        document.removeEventListener('keydown', keydown);
        document.removeEventListener('scroll', outsideScroll, true);
        window.removeEventListener('resize', resized);
    });
});
watch(() => props.disabled, value => { if (value) close(); });
</script>

<template>
    <button ref="trigger" type="button" class="icon-button" :class="{ 'ticket-menu-open': opened }" :aria-label="'Options for ticket ' + ticket.id" aria-haspopup="menu" :aria-expanded="opened" :aria-controls="opened ? menuId : undefined" :aria-disabled="disabled" @click="toggle" @keydown.down.prevent="!opened && toggle()"><Icon name="more" :size="18" /></button>
    <Teleport to="body">
        <div v-if="opened" :id="menuId" ref="menu" class="dropdown-menu ticket-options-menu" :style="position" role="menu" :aria-label="'Options for ticket ' + ticket.id">
            <div role="group" aria-label="Priority">
                <button v-for="priority in priorities" :key="priority" type="button" role="menuitemradio" :aria-checked="ticket.priority === priority" :class="{ current: ticket.priority === priority }" @click="choose({ priority })"><Icon :name="priorityIcon(priority)" :class="'priority-' + priority.toLowerCase()" :size="17" /><span>Set priority to {{ priority }}</span><Icon v-if="ticket.priority === priority" name="check" class="option-check" :size="17" /></button>
            </div>
            <div class="ticket-menu-divider" role="separator" />
            <div role="group" aria-label="Status">
                <button v-for="status in state.workspace.statuses" :key="status" type="button" role="menuitemradio" :aria-checked="ticket.status === status" :class="{ current: ticket.status === status }" @click="choose({ status })"><span>Set as {{ status }}</span><Icon v-if="ticket.status === status" name="check" class="option-check" :size="17" /></button>
            </div>
            <div class="ticket-menu-divider" role="separator" />
            <div role="group" aria-label="Ticket actions">
                <button type="button" role="menuitem" @click="choose({ unread: !ticket.unread })"><Icon name="mail" :size="17" />Mark {{ ticket.unread ? 'read' : 'unread' }}</button>
                <button type="button" role="menuitem" @click="choose({ assignee_id: state.user.id })"><Icon name="user" :size="17" /><span>Assign to me</span><Icon v-if="ticket.assignee_id === state.user.id" name="check" class="option-check" :size="17" /></button>
            </div>
            <div class="ticket-menu-divider" role="separator" />
            <div role="group" aria-label="Move ticket">
                <button v-for="folder in folders" :key="folder" type="button" role="menuitem" :class="{ 'trash-option': folder === 'trash' }" @click="choose({ folder })"><Icon :name="folder" :size="17" />Move to {{ folderLabel(folder) }}</button>
            </div>
        </div>
    </Teleport>
</template>

<style>
.ticket-menu-open{background:var(--soft)}
.ticket-options-menu{position:fixed;right:auto;z-index:40;width:252px;min-width:0;max-width:calc(100vw - 24px);max-height:calc(100dvh - 24px);overflow-y:auto;overscroll-behavior:contain;padding:6px}
.ticket-options-menu button{min-height:38px;padding:9px 10px;font-size:12px;line-height:20px}
.ticket-options-menu button.current{background:var(--soft)}
.ticket-options-menu button:focus-visible{outline:2px solid var(--accent);outline-offset:-2px;background:var(--soft)}
.ticket-options-menu .option-check{margin-left:auto;color:var(--accent);flex-shrink:0}
.ticket-options-menu .priority-urgent,.ticket-options-menu .priority-high,.ticket-options-menu .trash-option{color:var(--status-danger-ink)}
.ticket-options-menu .priority-normal{color:var(--muted);fill:currentColor;width:10px;margin-inline:3.5px}
.ticket-options-menu .priority-low{color:var(--status-solved-ink)}
.ticket-menu-divider{height:1px;background:var(--line);margin:6px -6px}
</style>
