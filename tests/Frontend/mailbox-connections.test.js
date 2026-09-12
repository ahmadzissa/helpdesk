import test from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, reactive, ref } from 'vue';

globalThis.document = { querySelector: () => ({ content: 'synthetic-csrf-token' }) };
const { useMailboxConnections } = await import('../../resources/js/useMailboxConnections.js');
const success = () => ({ smtp: { success: true }, imap: { success: true }, token: 'server-proof', expires_at: new Date(Date.now() + 600000).toISOString() });
function fixture(request = async () => success(), id = null) {
    const scope = effectScope();
    const form = reactive({ smtp_host: 'smtp.example.com', smtp_port: 587, smtp_encryption: 'tls', smtp_username: 'support', smtp_password: 'secret', imap_host: 'imap.example.com', imap_port: 993, imap_encryption: 'ssl', imap_username: 'support', imap_password: 'secret', incoming_enabled: false, sending_enabled: false });
    const modal = ref({ type: 'mailboxes', id });
    const connection = scope.run(() => useMailboxConnections(form, modal, request));
    return { form, modal, connection, stop: () => scope.stop() };
}

test('adding an account stays blocked until both current connections pass', async () => {
    const { connection, stop } = fixture();
    assert.equal(connection.canSave.value, false);
    await connection.test();
    assert.equal(connection.canSave.value, true);
    assert.equal(connection.token.value, 'server-proof');
    stop();
});

test('failure of either protocol and malformed or expired approval block account creation', async () => {
    for (const result of [
        { ...success(), smtp: { success: false } },
        { ...success(), imap: { success: false } },
        { ...success(), token: null },
        { ...success(), expires_at: new Date(0).toISOString() },
    ]) {
        const { connection, stop } = fixture(async () => result);
        await connection.test();
        assert.equal(connection.canSave.value, false);
        assert.equal(connection.passed.value, false);
        stop();
    }
});

test('editing credentials invalidates success while changing display fields or enable switches preserves it', async () => {
    const { connection, form, stop } = fixture();
    await connection.test();
    form.name = 'Customer care'; form.sending_enabled = true; form.incoming_enabled = true;
    assert.equal(connection.canSave.value, true);
    form.smtp_password = 'changed';
    assert.equal(connection.canSave.value, false);
    assert.equal(connection.token.value, '');
    stop();
});

test('a result for credentials changed during testing cannot enable saving', async () => {
    let finish, signal;
    const { connection, form, stop } = fixture(async (url, options) => { signal = options.signal; return new Promise(resolve => { finish = resolve; }); });
    const pending = connection.test();
    assert.equal(connection.testing.value, true);
    form.imap_host = 'other.example.com';
    assert.equal(signal.aborted, true);
    finish(success()); await pending;
    assert.equal(connection.canSave.value, false);
    assert.equal(connection.results.value, null);
    stop();
});

test('saved accounts can edit metadata but need fresh tests for connection changes or activation', async () => {
    const { connection, form, stop } = fixture(undefined, 4);
    assert.equal(connection.canSave.value, true);
    form.name = 'New name';
    assert.equal(connection.canSave.value, true);
    form.incoming_enabled = true;
    assert.equal(connection.canSave.value, false);
    await connection.test();
    assert.equal(connection.canSave.value, true);
    form.imap_port = 143;
    assert.equal(connection.canSave.value, false);
    stop();
});

test('closing the modal clears results and new accounts cannot reuse them', async () => {
    const { connection, form, modal, stop } = fixture();
    await connection.test();
    modal.value = null;
    form.smtp_host = 'another.example.com';
    modal.value = { type: 'mailboxes', id: null };
    assert.equal(connection.canSave.value, false);
    assert.equal(connection.token.value, '');
    stop();
});

test('request errors are shown and keep Save blocked', async () => {
    const { connection, stop } = fixture(async () => { throw new Error('Connection timed out'); });
    await connection.test();
    assert.equal(connection.canSave.value, false);
    assert.equal(connection.testing.value, false);
    assert.equal(connection.error.value, 'Connection timed out');
    stop();
});
