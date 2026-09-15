<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import LanguagePicker from './LanguagePicker.vue';
import PriorityIcon from './PriorityIcon.vue';

const props = defineProps({ form: { type: Object, required: true }, workspace: { type: Object, required: true }, saving: Boolean, error: String });
defineEmits(['submit', 'cancel']);
const mailbox = computed(() => props.workspace.mailboxes.find(box => box.id === props.form.mailbox_id));
const delivery = computed(() => {
    if (props.workspace.sending_safety?.paused) return { icon: 'pause', title: 'Sending is paused', detail: 'Your ticket will be created and the message held for review.', action: 'Create ticket' };
    if (!mailbox.value?.sending_enabled) return { icon: 'file', title: 'Save without sending', detail: 'Your message will be saved. Choose a sending mailbox to email the customer.', action: 'Create ticket' };
    if (props.workspace.translation?.outgoing) return { icon: 'globe', title: 'Prepare for translation', detail: 'Create the ticket, then prepare your first reply for delivery.', action: 'Create & prepare reply' };
    return { icon: 'send', title: 'Ready to send', detail: 'Your first message will be emailed to the customer when you create this ticket.', action: 'Create & send' };
});
function selectMailbox() { props.form.team_id = mailbox.value?.team_id || null; }
</script>

<template>
<form class="ticket-create-form" :aria-busy="saving" @submit.prevent="$emit('submit')">
    <div class="ticket-create-scroll">
        <p v-if="error" class="error-message" role="alert">{{ error }}</p>
        <fieldset class="ticket-create-layout" :disabled="saving">
            <div class="ticket-create-main">
                <section class="ticket-create-section" aria-labelledby="ticket-customer-heading">
                    <div class="ticket-create-heading"><span class="ticket-create-section-icon"><Icon name="user" :size="18" /></span><div><h3 id="ticket-customer-heading">Who are you helping?</h3><p>Add the customer for this conversation.</p></div></div>
                    <div class="ticket-create-customer">
                        <label for="new-ticket-email">Customer email <input id="new-ticket-email" v-model="form.requester_email" type="email" required maxlength="255" placeholder="customer@company.com" autocomplete="email" autofocus /></label>
                        <label for="new-ticket-name"><span>Name <span class="ticket-create-optional">Optional</span></span><input id="new-ticket-name" v-model="form.requester_name" maxlength="100" placeholder="Customer name" autocomplete="name" /></label>
                    </div>
                </section>
                <section class="ticket-create-section ticket-create-conversation" aria-labelledby="ticket-message-heading">
                    <div class="ticket-create-heading"><span class="ticket-create-section-icon"><Icon name="message" :size="18" /></span><div><h3 id="ticket-message-heading">Start the conversation</h3><p>Write the first message to your customer.</p></div></div>
                    <label for="new-ticket-subject">Subject<input id="new-ticket-subject" v-model="form.subject" required maxlength="255" placeholder="A short summary of the conversation" /></label>
                    <div class="ticket-create-message">
                        <label for="new-ticket-message"><Icon name="mail" :size="15" />Message to the customer</label>
                        <textarea id="new-ticket-message" v-model="form.body" required rows="8" placeholder="Hi there,&#10;&#10;How can we help?" aria-describedby="new-ticket-delivery" />
                    </div>
                </section>
            </div>
            <aside class="ticket-create-details" aria-label="Ticket details">
                <div class="ticket-create-details-heading"><Icon name="filters" :size="16" /><h3>Ticket details</h3></div>
                <label for="new-ticket-mailbox">Send from<select id="new-ticket-mailbox" v-model="form.mailbox_id" @change="selectMailbox"><option :value="null">No mailbox</option><option v-for="box in workspace.mailboxes" :key="box.id" :value="box.id">{{ box.name }}</option></select></label>
                <p v-if="mailbox" class="ticket-create-mailbox" dir="ltr">{{ mailbox.email }}</p>
                <label for="new-ticket-priority">Priority<div class="ticket-create-priority"><PriorityIcon :priority="form.priority" /><select id="new-ticket-priority" v-model="form.priority"><option v-for="priority in ['Low', 'Normal', 'High', 'Urgent']" :key="priority">{{ priority }}</option></select></div></label>
                <div v-if="workspace.translation?.outgoing" class="ticket-create-language"><span class="ticket-create-field-label">Customer language <span class="ticket-create-optional">Optional</span></span><LanguagePicker v-model="form.customer_language" label="Customer language" :disabled="saving" /><p>Leave empty to use the customer’s saved language.</p></div>
                <div id="new-ticket-delivery" class="ticket-create-delivery" role="status"><span class="ticket-create-delivery-icon"><Icon :name="delivery.icon" :size="19" /></span><strong>{{ delivery.title }}</strong><p>{{ delivery.detail }}</p></div>
            </aside>
        </fieldset>
    </div>
    <footer class="ticket-create-footer">
        <span class="ticket-create-footer-note"><Icon name="message" :size="15" />A new conversation starts here.</span>
        <div><button type="button" class="secondary-button" :disabled="saving" @click="$emit('cancel')">Cancel</button><button type="submit" class="primary-button" :disabled="saving"><Icon :name="saving ? 'loader' : delivery.icon" :class="{ spin: saving }" :size="16" />{{ saving ? 'Creating…' : delivery.action }}</button></div>
    </footer>
</form>
</template>

<style scoped>
:global(.modal.wide.ticket-create-modal){width:940px;max-height:92dvh}
:global(.ticket-create-modal>.modal-content){padding:0;overflow:hidden;display:flex;flex-direction:column;min-height:0}
:global(.modal.ticket-create-modal>header){padding:21px 28px}
:global(.modal.ticket-create-modal>header h2){font-size:20px;letter-spacing:-.5px}
.ticket-create-form{display:flex;flex-direction:column;min-height:0}
.ticket-create-scroll{overflow-y:auto;min-height:0;overscroll-behavior:contain}
.ticket-create-scroll>.error-message{margin:20px 28px 0}
.ticket-create-layout{display:grid;grid-template-columns:minmax(0,1fr) 270px;min-width:0;padding:0;margin:0;border:0}
.ticket-create-main{min-width:0;padding:28px}
.ticket-create-section{display:flex;flex-direction:column;gap:20px}
.ticket-create-heading{display:flex;gap:12px;align-items:center}
.ticket-create-section-icon{width:36px;height:36px;display:grid;place-items:center;background:var(--soft);border:1px solid var(--line);border-radius:10px;color:var(--accent-ink)}
.ticket-create-heading h3{font-size:14px;font-weight:650}
.ticket-create-heading p{margin-top:4px;color:var(--muted);font-size:12px;line-height:1.5}
.ticket-create-customer{display:grid;grid-template-columns:1.15fr 1fr;gap:16px}
.ticket-create-optional{font-size:11px;color:var(--muted);font-weight:400;margin-left:5px}
.ticket-create-conversation{margin-top:25px;padding-top:25px;border-top:1px solid var(--line);gap:18px}
.ticket-create-message{border:1px solid var(--line);border-radius:10px;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.ticket-create-message:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 9%,transparent)}
.ticket-create-message>label{display:flex;flex-direction:row;align-items:center;gap:8px;padding:12px 14px;background:var(--bg);border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)}
.ticket-create-message textarea{display:block;min-height:190px;border:0;border-radius:0;padding:16px;line-height:1.8;box-shadow:none;background:var(--surface)}
.ticket-create-details{min-width:0;background:color-mix(in srgb,var(--bg) 75%,var(--surface));border-left:1px solid var(--line);padding:28px 22px;display:flex;flex-direction:column;gap:22px}
.ticket-create-details-heading{display:flex;align-items:center;gap:8px;color:var(--muted);margin-bottom:3px}
.ticket-create-details-heading h3{font-size:12px;font-weight:600}
.ticket-create-mailbox{font-size:11px;line-height:1.6;color:var(--muted);overflow-wrap:anywhere;margin-top:-15px}
.ticket-create-priority{position:relative}
.ticket-create-priority>.priority-indicator{position:absolute;top:50%;left:12px;transform:translateY(-50%);pointer-events:none}
.ticket-create-priority select{padding-left:37px}
.ticket-create-language{display:flex;flex-direction:column;gap:8px}
.ticket-create-field-label{font-size:13px;font-weight:550}
.ticket-create-language>p{font-size:11px;line-height:1.65;color:var(--muted)}
.ticket-create-delivery{margin-top:auto;padding-top:24px;border-top:1px solid var(--line)}
.ticket-create-delivery-icon{display:flex;color:var(--accent-ink);margin-bottom:12px}
.ticket-create-delivery strong{display:block;font-size:12px}
.ticket-create-delivery p{font-size:12px;line-height:1.75;color:var(--muted);margin-top:6px}
.ticket-create-footer{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:17px 28px;border-top:1px solid var(--line);background:var(--surface);flex-shrink:0}
.ticket-create-footer-note{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted)}
.ticket-create-footer>div{display:flex;align-items:center;gap:9px}
.ticket-create-footer .primary-button{min-height:40px;padding-inline:18px}
@media(max-width:760px){
    .ticket-create-layout{grid-template-columns:minmax(0,1fr)}
    .ticket-create-details{border-left:0;border-top:1px solid var(--line);padding:22px 24px;display:grid;grid-template-columns:1fr 1fr;gap:18px}
    .ticket-create-details-heading,.ticket-create-mailbox,.ticket-create-language,.ticket-create-delivery{grid-column:1/-1}
    .ticket-create-details>label:first-of-type{grid-column:1/-1}
    .ticket-create-details>label:nth-of-type(2){grid-column:1/-1}
    .ticket-create-delivery{padding-top:18px;margin-top:0}
    .ticket-create-footer-note{display:none}
    .ticket-create-footer{justify-content:flex-end}
}
@media(max-width:480px){
    :global(.modal.ticket-create-modal>header){padding:17px 18px}
    .ticket-create-main{padding:22px 18px}
    .ticket-create-customer{grid-template-columns:1fr;gap:16px}
    .ticket-create-details{padding:22px 18px}
    .ticket-create-footer{padding:14px 18px}
    .ticket-create-footer>div{width:100%}
    .ticket-create-footer .primary-button{flex:1;padding-inline:10px}
    .ticket-create-scroll>.error-message{margin-inline:18px}
}
</style>
