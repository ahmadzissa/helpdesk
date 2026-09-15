export function createLocalMailPoller({ enabled, visible, request, refreshed, failed }) {
    let pending = false, disposed = false, lastError = '';
    return {
        async poll() {
            if (disposed || pending || !enabled() || !visible()) return;
            pending = true;
            try {
                const result = await request();
                if (disposed || !enabled()) return;
                if (result.checked || result.queued) refreshed();
                const error = (result.errors || []).map(item => item.message).join(' ');
                if (error && error !== lastError) failed(error);
                lastError = error;
            } catch (error) {
                if (!disposed && enabled() && error.message !== lastError) {
                    lastError = error.message;
                    failed(error.message);
                }
            } finally { pending = false; }
        },
        dispose() { disposed = true; },
    };
}
