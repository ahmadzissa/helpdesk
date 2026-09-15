let applicationBasePath = '';

export function setAppBasePath(path = '') {
    applicationBasePath = path.replace(/\/$/, '');
}

export function appUrl(destination, basePath = applicationBasePath) {
    const path = typeof destination === 'string' ? destination : destination.path;
    const url = basePath.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
    if (typeof destination === 'string' || !destination.query) return url;
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(destination.query)) {
        if (value !== undefined && value !== null) query.set(key, String(value));
    }
    return url + (query.size ? '?' + query : '');
}

export function messageHtml(html = '') {
    return html.replace(/src="(\/api\/v1\/(?:(?:inline-images|canned-images)\/[a-f0-9-]{36}|attachments\/\d+\/\d+\/inline))"/gi, (_, path) => `src="${appUrl(path)}"`);
}
