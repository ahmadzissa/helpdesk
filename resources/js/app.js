import { createApp, h } from 'vue';
import { createInertiaApp, Head, Link } from '@inertiajs/vue3';
import AppLayout from './App.vue';
import Icon from './components/Icon.vue';
import { appUrl } from './urls';

const pages = import.meta.glob('./pages/*.vue');

createInertiaApp({
    resolve: async name => {
        const { default: page } = await pages[`./pages/${name}.vue`]();
        page.layout = AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });
        app.config.globalProperties.$appUrl = appUrl;
        app.use(plugin).component('Icon', Icon).component('Link', Link).component('Head', Head).mount(el);
    },
    progress: { color: '#7450bb' },
});

