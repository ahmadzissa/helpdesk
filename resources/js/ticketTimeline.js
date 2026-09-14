export function ticketTimeline(messages = [], activities = []) {
    return [
        ...messages.map(message => ({ key: 'message-' + message.id, message, entry: null, timestamp: Date.parse(message.created_at), order: 0, id: message.id })),
        ...activities.map(entry => ({ key: 'activity-' + entry.id, message: null, entry, timestamp: Date.parse(entry.created_at), order: 1, id: entry.id })),
    ].sort((a, b) => a.timestamp - b.timestamp || a.order - b.order || a.id - b.id);
}
