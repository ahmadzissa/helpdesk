import test from 'node:test';
import assert from 'node:assert/strict';
import { createRenderer, ref, nextTick } from 'vue';

globalThis.document = { querySelector: () => ({ content: 'synthetic-csrf-token' }) };
const { useTicketTranslation } = await import('../../resources/js/useTicketTranslation.js');
const { state } = await import('../../resources/js/store.js');
const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert() {}, remove() {}, setText() {}, setElementText() {}, parentNode() {}, nextSibling() {}, patchProp() {},
});
const context = { recipient: 'synthetic@example.com', cc: [], target: 'es', subject_hash: 'subject', revision: 1, inbound_id: 7 };
const fixture = () => ({ id: 1, subject: 'My order', messages: [], translation_context: { ...context }, customer_language: { language: 'es', manual: true }, language_sample: null });
function mount(google, makeFixture = fixture, detectedLanguage = 'en') {
    state.workspace.translation = { incoming: false, outgoing: true, target: 'en', revision: 1 };
    const ticket = ref(makeFixture()), body = ref('Hello, we can help.'), privateNote = ref(false);
    let translation;
    globalThis.fetch = async (url, options) => {
        let result;
        if (url === '/api/v1/translation/config') result = { key: 'synthetic-test-key', settings: state.workspace.translation };
        else if (url === '/api/v1/tickets/1') result = { ticket: makeFixture() };
        else if (url.endsWith('/v2/detect')) result = { data: { detections: [[{ language: detectedLanguage }]] } };
        else return google(url, options);
        return { ok: true, json: async () => result };
    };
    const app = renderer.createApp({ setup() { translation = useTicketTranslation(ticket, body, privateNote); return () => null; } });
    app.mount({});
    return { ticket, body, privateNote, translation, stop: () => app.unmount() };
}
const echoGoogle = async (url, options) => ({ ok: true, json: async () => [JSON.parse(options.body)[0][0].map(text => 'ES: ' + text), ['en']] });

test('disabled tickets skip automatic and manual translation and send without a preview', async () => {
    const mounted = mount(() => assert.fail('Disabled tickets must not translate'), () => ({ ...fixture(), translation_enabled: false }));
    let requests = 0;
    globalThis.fetch = async () => { requests++; assert.fail('Disabled tickets must not request translation or detection'); };
    try {
        assert.equal(mounted.translation.autoReply.value, false);
        assert.equal(await mounted.translation.prepareToSend(true), true);
        assert.equal(await mounted.translation.prepare(), false);
        await mounted.translation.translateAll(true);
        await mounted.translation.translateMessage({ id: 7, body: 'Hola' }, true);
        await mounted.translation.detectLanguage(true);
        assert.equal(requests, 0);
        assert.equal(state.workspace.translation.outgoing, true);
    } finally { mounted.stop(); }
});

test('turning translation off cancels a pending reply and turning it on restores translation', async () => {
    let release, started;
    const requested = new Promise(resolve => { started = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const mounted = mount(async (url, options) => { started(); await gate; return echoGoogle(url, options); });
    try {
        const work = mounted.translation.prepare();
        await requested;
        mounted.ticket.value.translation_enabled = false;
        release();
        assert.equal(await work, false);
        assert.equal(mounted.translation.preview.value, null);
        assert.equal(mounted.translation.translationError.value, '');
        assert.equal(await mounted.translation.prepareToSend(true), true);
        mounted.ticket.value.translation_enabled = true;
        assert.equal(mounted.translation.autoReply.value, true);
        assert.equal(await mounted.translation.prepare(), true);
    } finally { mounted.stop(); }
});

for (const [language, body] of [['en', 'Hello, we can help.'], ['ar', 'مرحباً، يمكننا مساعدتك.']]) {
    test(`matching ${language} replies send immediately even when translated replies require preview`, async () => {
        const makeFixture = () => ({ ...fixture(), translation_context: { ...context, target: language }, customer_language: { language, manual: true } });
        const mounted = mount(() => assert.fail('Matching replies must not be translated'), makeFixture, language);
        mounted.body.value = body;
        try {
            assert.equal(await mounted.translation.prepareToSend(), true);
            assert.equal(mounted.translation.preview.value.body, body);
            assert.equal(mounted.translation.preview.value.sameLanguage, true);
            mounted.body.value += '!';
            assert.equal(mounted.translation.previewReady.value, false);
            mounted.ticket.value.translation_context.target = 'fr';
            assert.equal(mounted.translation.previewReady.value, false);
        } finally { mounted.stop(); }
    });
}

test('the Vue composer invalidates a preview immediately after editing the original or switching to a note', async () => {
    const mounted = mount(echoGoogle);
    try {
        assert.equal(await mounted.translation.prepare(), true);
        assert.equal(mounted.translation.previewReady.value, true);
        mounted.body.value = 'A different reply';
        assert.equal(mounted.translation.previewReady.value, false);
        assert.equal(await mounted.translation.prepare(), true);
        mounted.privateNote.value = true;
        assert.equal(mounted.translation.previewReady.value, false);
        assert.equal(mounted.translation.autoReply.value, false);
    } finally { mounted.stop(); }
});

test('editing while Google is still translating cancels the pending preview', async () => {
    let release, started;
    const requested = new Promise(resolve => { started = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const mounted = mount(async (url, options) => { started(); await gate; return echoGoogle(url, options); });
    try {
        const work = mounted.translation.prepare();
        await requested;
        mounted.body.value = 'Edited while translating';
        release();
        assert.equal(await work, false);
        assert.equal(mounted.translation.preview.value, null);
        assert.equal(mounted.translation.translating.value, false);
    } finally { mounted.stop(); }
});

test('a Google failure stays visible in the composer and cannot produce a sendable preview', async () => {
    const mounted = mount(async () => ({ ok: false, status: 429 }));
    try {
        assert.equal(await mounted.translation.prepare(), false);
        assert.match(mounted.translation.translationError.value, /quota/);
        assert.equal(mounted.translation.previewReady.value, false);
    } finally { mounted.stop(); }
});

test('automatic mode can translate and continue sending in one action in the customer language', async () => {
    let calls = 0;
    const mounted = mount(async (url, options) => {
        calls++;
        assert.equal(JSON.parse(options.body)[0][2], 'es');
        return echoGoogle(url, options);
    });
    state.workspace.translation.auto_send = true;
    try {
        assert.equal(await mounted.translation.prepareToSend(), true);
        assert.equal(mounted.translation.preview.value.body, 'ES: Hello, we can help.');
        assert.equal(mounted.translation.preview.value.subject, 'My order');
        assert.equal(calls, 1);
    } finally { mounted.stop(); }
});

test('review mode pauses after translation and private notes skip translation', async () => {
    let calls = 0;
    const mounted = mount(async (url, options) => { calls++; return echoGoogle(url, options); });
    try {
        assert.equal(await mounted.translation.prepareToSend(), false);
        assert.equal(await mounted.translation.prepareToSend(), true);
        mounted.privateNote.value = true;
        assert.equal(await mounted.translation.prepareToSend(), true);
        assert.equal(calls, 1);
    } finally { mounted.stop(); }
});

test('automatic translation failures keep the draft and allow a successful retry', async () => {
    let fail = true;
    const mounted = mount(async (url, options) => fail ? { ok: false, status: 429 } : echoGoogle(url, options));
    state.workspace.translation.auto_send = true;
    try {
        assert.equal(await mounted.translation.prepareToSend(), false);
        assert.equal(mounted.body.value, 'Hello, we can help.');
        assert.match(mounted.translation.translationError.value, /quota/);
        fail = false;
        assert.equal(await mounted.translation.prepareToSend(), true);
        assert.equal(mounted.translation.translationError.value, '');
    } finally { mounted.stop(); }
});

test('a customer language change invalidates an otherwise complete Vue preview', async () => {
    const mounted = mount(echoGoogle);
    try {
        assert.equal(await mounted.translation.prepare(), true);
        mounted.ticket.value.translation_context.target = 'ar';
        await nextTick();
        assert.equal(mounted.translation.previewReady.value, false);
        assert.equal(mounted.translation.preview.value, null);
    } finally { mounted.stop(); }
});


test('detecting from another ticket for the same email preserves the current ticket reply context', async () => {
    const makeFixture = () => ({ ...fixture(), customer_language: null, language_sample: { id: 7, body: 'Hola', source_hash: 'synthetic-hash' } });
    const mounted = mount(async (url, options) => {
        if (url === '/api/v1/messages/7/translation') return { ok: true, json: async () => ({
            customer_language: { language: 'es', manual: false, source_message_id: 7 },
            translation_context: { ...context, subject_hash: 'another-ticket-subject', cc: ['different-cc@example.com'] },
            translation: { body: 'Hello', target_language: 'en', source_hash: 'synthetic-hash' },
        }) };
        return echoGoogle(url, options);
    }, makeFixture);
    try {
        assert.equal(await mounted.translation.prepare(), true);
        assert.equal(mounted.translation.preview.value.context.subject_hash, 'subject');
        assert.deepEqual(mounted.translation.preview.value.context.cc, []);
        assert.equal(mounted.ticket.value.customer_language.language, 'es');
    } finally { mounted.stop(); }
});
