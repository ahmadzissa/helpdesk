const condition = (field, operator, value, options = {}) => ({ field, operator, value: String(value), ...options });
const rule = (name, description, trigger, all, actions, any = []) => ({ name, description, enabled: false, trigger, repeat_mode: 'once', interval_minutes: 60, max_runs: 1, conditions: { all, any }, actions });
const acknowledgment = 'Dear Customer,\n\nWe would like to acknowledge that we have received your request. One of the support team will answer your question shortly.\n\nThank you for your patience.\n\nSincerely,\nAreviews Support Team';

export const workflowTemplates = [
    { ...rule('Pending to Closed', 'Close Pending tickets two days after the latest status change, sent message, or sent follow-up.', 'time.elapsed', [
        condition('status', 'eq', 'Pending'), condition('since_events_minutes', 'gte', 2880, { events: ['status_changed', 'message_sent', 'follow_up_sent'] }),
    ], [{ type: 'status', value: 'Closed' }]), repeat_mode: 'interval', max_runs: 1000 },
    rule('Follow-up', 'When Pending for one day since the latest status change or sent message, send a follow-up if nobody has sent one before.', 'time.elapsed', [
        condition('status', 'eq', 'Pending'), condition('follow_up_count', 'eq', 0, { scope: 'anyone' }),
        condition('since_events_minutes', 'gte', 1440, { events: ['status_changed', 'message_sent'] }),
    ], [{ type: 'send_follow_up', value: "Hello {{name}},\n\nWe haven't heard back from you for some time. If you need any further help, please follow up on this email.\n\nThank you." }]),
    rule('New ticket confirmation', 'Acknowledge the first requester message, excluding installation and Amazon subjects.', 'ticket.created', [
        condition('customer_message_count', 'eq', 1), condition('subject', 'not_contains', 'New Installation request Plan:'), condition('subject', 'not_contains', 'amazon'),
    ], [{ type: 'send_message', value: acknowledgment }]),
    rule('Set status to Closed From Shopify', 'Close new tickets from no-reply@mailer.shopify.com.', 'ticket.created', [
        condition('requester_email', 'eq', 'no-reply@mailer.shopify.com'),
    ], [{ type: 'status', value: 'Closed' }]),
    rule('Move to Spam', 'Move new data erasure request emails to spam.', 'ticket.created', [
        condition('subject', 'contains', 'Areviewsapp data erasure request - from'),
    ], [{ type: 'folder', value: 'spam' }]),
    { ...rule('no reply for 4 hours', 'Open and inactive for four hours, with exactly one previous follow-up sent by this same rule. This restriction prevents it from starting on a fresh ticket.', 'time.elapsed', [
        condition('status', 'eq', 'Open'), condition('since_events_minutes', 'gte', 240, { events: ['activity'] }),
        condition('follow_up_count', 'eq', 1, { scope: 'rule' }),
    ], [{ type: 'send_follow_up', value: 'We hope this message finds you well. Sorry for any delay. One of our team will reach out to you soon.\n\nThank you for your patience.' }]), repeat_mode: 'interval', max_runs: 2 },
    rule('amazon issue', 'On a new ticket mentioning Amazon, send the extension instructions.', 'ticket.created', [], [
        { type: 'send_message', value: 'Dear Customer,\n\nWe would like to acknowledge that we have received your request. One of the support team will answer your question shortly.\n\nIf the issue is regarding importing from Amazon, you can try to import using our extension:\n\n1. Download the extension: https://areviewsapp.com/areviewsExtension\n2. Go to an Amazon product page.\n3. Click our app icon on the right side of the product page.\n4. Choose the product from your store, then click Import.\n\nThank you for your patience.\n\nSincerely,\nAreviews Support Team' },
    ], [condition('body', 'contains_phrase', 'amazon'), condition('body', 'contains_phrase', 'amazon issue')]),
    rule('Send message', 'On a new ticket mentioning agentic or Shopify Shop, send your existing reply. Review its wording before enabling.', 'ticket.created', [], [
        { type: 'send_message', value: "If your Question Regarding Shopify agentic storefronts or Shopify Shop these only accept\n1- imported reviews\n2- Csv reviews\n3- manual reviews\n\nIt's very strict Shopify reviews system that only accept reviews that\n1- written by email requests\n2- linked with an order in your store\n3- verified by Shopify team\n\nso all your current reviews can't be added no matter what about you using this apply for\n1-Shopify agentic storefronts\n2- Shopify Shop App\n\nAnyway in next few days we will release agentic storefronts support for customers throw emails requests.\n\nI hope it's clear" },
    ], [condition('body', 'contains_phrase', 'agentic'), condition('body', 'contains_phrase', 'Shopify Shop')]),
];
