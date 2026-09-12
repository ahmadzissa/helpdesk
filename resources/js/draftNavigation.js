export function guardDraftNavigation(router, { isDirty, isSending, save, onError }) {
    let destination = null, saving = false, disposed = false;
    const remove = router.on('before', event => {
        if (isSending()) {
            event.preventDefault();
            onError('Wait for your reply to finish sending before leaving.');
            return;
        }
        if (!isDirty()) return;
        event.preventDefault();
        destination = event.detail.visit;
        if (!saving) flush();
    });

    async function flush() {
        saving = true;
        try {
            do { await save(); } while (!disposed && isDirty());
            if (!disposed) router.visit(destination.url, destination);
        } catch (error) {
            onError('Couldn’t save your draft. ' + error.message);
        } finally { saving = false; destination = null; }
    }

    return () => { disposed = true; remove(); };
}
