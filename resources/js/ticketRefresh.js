export function createTicketRefresher({ getId, fetchTicket, apply }) {
    let pending = null, revision = 0, disposed = false;
    function invalidate() { revision++; pending = null; }
    async function refresh() {
        if (disposed || !getId()) return;
        if (pending) return pending;
        const id = getId(), startedRevision = revision;
        const request = Promise.resolve().then(() => fetchTicket(id)).then(result => {
            if (!disposed && revision === startedRevision && getId() === id) apply(result);
        }).finally(() => { if (pending === request) pending = null; });
        pending = request;
        return request;
    }
    return { refresh, invalidate, dispose() { disposed = true; invalidate(); } };
}
