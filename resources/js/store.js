import { reactive } from 'vue';
import { appUrl, setAppBasePath } from './urls.js';

export const state = reactive({
    user: null, needsSetup: false, workspace: { agents: [], mailboxes: [], teams: [], views: [], replies: [], settings: {} },
    counts: {}, viewCounts: [], scope: 'all', toast: '', toastError: false, mobileNav: false, refresh: 0,
});
let token = document.querySelector('meta[name="csrf-token"]').content;
let toastTimer;
export function notify(message, error = false) {
    state.toast = message; state.toastError = error;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { state.toast = ''; }, 5000);
}
export async function api(path, options = {}) {
    const form = options.body instanceof FormData;
    const response = await fetch(appUrl('/api/v1/' + path), {
        credentials: 'same-origin', ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, ...(form ? {} : { 'Content-Type': 'application/json' }), ...options.headers },
        body: options.body === undefined ? undefined : form ? options.body : JSON.stringify(options.body),
    });
    const data = await response.json().catch(() => ({}));
    if (data.csrf_token) { token = data.csrf_token; }
    if (!response.ok) {
        if (response.status === 401 && typeof window !== 'undefined') { window.location.assign(appUrl('/login')); }
        const message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Something went wrong. Please try again.';
        throw new Error(response.status === 419 ? 'Your session expired. Reload the page and try again.' : message);
    }
    return data;
}
export async function bootstrap() {
    state.workspace = await api('workspace');
    state.user = state.workspace.user;
    applyTheme();
    await refreshSidebar();
}
export async function refreshSendingSafety() {
    if (state.user) state.workspace.sending_safety = await api('sending-safety');
}
export async function refreshSidebar() {
    const params = new URLSearchParams({ counts_only: '1' });
    if (state.scope !== 'all') { const [key, value] = state.scope.split(':'); params.set(key + '_id', value); }
    const data = await api('tickets?' + params);
    state.counts = data.counts; state.viewCounts = data.views;
}
export function syncPage(props) {
    setAppBasePath(props.base_path);
    token = props.csrf_token;
    state.user = props.auth.user;
    state.needsSetup = props.needs_setup;
    state.workspace = props.workspace || { agents: [], mailboxes: [], teams: [], views: [], replies: [], settings: {} };
    if (!state.user) { state.scope = 'all'; state.counts = {}; state.viewCounts = []; }
    applyTheme();
}
export const initials = (name = '') => name.replace(/@.*/, '').split(/[ ._-]+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
export function relativeTime(date) {
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(date)) / 60000));
    return minutes < 1 ? 'now' : minutes < 60 ? minutes + 'm' : minutes < 1440 ? Math.floor(minutes / 60) + 'h' : Math.floor(minutes / 1440) + 'd';
}
export const palettes = [
    { id: 'helpdesk', name: 'HelpDesk', accent: '#0059e1', rail: '#050505', background: '#ffffff', surface: '#ffffff' },
    { id: 'orchid', name: 'Orchid', accent: '#7450bb', rail: '#272331', background: '#f8f6fc', surface: '#ffffff' },
    { id: 'ocean', name: 'Ocean', accent: '#245fc2', rail: '#192a43', background: '#f5f8fd', surface: '#ffffff' },
    { id: 'forest', name: 'Forest', accent: '#24765e', rail: '#1d302b', background: '#f5faf7', surface: '#ffffff' },
    { id: 'ember', name: 'Ember', accent: '#a14d2c', rail: '#342720', background: '#fcf8f4', surface: '#ffffff' },
    { id: 'slate', name: 'Slate', accent: '#4b607e', rail: '#202731', background: '#f6f8fa', surface: '#ffffff' },
];
const rgb = color => color.slice(1).match(/../g).map(x => parseInt(x, 16));
const mix = (a, b, w) => '#' + rgb(a).map((n, i) => Math.round(n * (1 - w) + rgb(b)[i] * w).toString(16).padStart(2, '0')).join('');
const luminance = c => rgb(c).map(v => (v /= 255) <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((a, v, i) => a + v * [.2126, .7152, .0722][i], 0);
const ink = c => luminance(c) > .179 ? '#171a21' : '#ffffff';
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);
const readable = (color, bg) => {
    for (let w = 0; w <= 1; w += .05) { const c = mix(color, ink(bg), w); if (contrast(c, bg) >= 4.5) return c; }
    return ink(bg);
};
export function applyTheme(preferences = state.user?.preferences || {}) {
    const palette = preferences.theme === 'custom' ? { ...palettes[1], ...preferences.colors } : palettes.find(p => p.id === preferences.theme) || palettes[1];
    const { accent, rail, background, surface } = palette;
    const text = mix(accent, ink(surface), .85), soft = mix(surface, accent, .07);
    const tokens = { accent, rail, bg: background, surface, text: readable(text, surface), muted: readable(mix(surface, text, .65), surface), soft,
        line: mix(surface, ink(surface), .13), selected: mix(surface, accent, .13), 'on-accent': ink(accent), 'accent-ink': readable(accent, surface),
        'rail-text': readable(mix(rail, ink(rail), .7), rail), 'rail-active': mix(rail, ink(rail), .15), 'automation-header': mix('#0059e1', accent, preferences.theme === 'helpdesk' ? 0 : .35) };
    tokens['automation-ink'] = ink(tokens['automation-header']);
    const seeds = { open: accent, pending: '#b77908', 'on-hold': '#8250df', solved: '#168447', closed: '#606575', danger: '#cf354b' };
    for (const [name, seed] of Object.entries(seeds)) {
        const color = mix(seed, accent, .15), bg = mix(surface, color, .12);
        tokens['status-' + name] = readable(color, surface);
        tokens['status-' + name + '-bg'] = bg;
        tokens['status-' + name + '-ink'] = readable(color, bg);
    }
    for (const [key, value] of Object.entries(tokens)) { document.documentElement.style.setProperty('--' + key, value); }
    document.documentElement.style.colorScheme = luminance(surface) < .18 ? 'dark' : 'light';
}
export const statusClass = value => value.toLowerCase().replaceAll(' ', '-');

