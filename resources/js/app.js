import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, Fragment, h } from 'vue';
import Toast from './Components/Toast.vue';
import '../css/app.css';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} · Secret Santa HQ` : 'Secret Santa HQ'),
    resolve: (name) => {
        const path = `./Pages/${name}.vue`;
        const page = pages[path];
        if (!page) {
            throw new Error(`Inertia page not found: ${name} (${path})`);
        }
        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(Fragment, [h(App, props), h(Toast)]),
        })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#C427FB',
    },
});
