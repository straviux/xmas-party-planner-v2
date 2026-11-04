import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, Fragment, h } from 'vue';
import Toast from './Components/Toast.vue';
import '../css/app.css';

const pages = import.meta.glob('./Pages/**/*.vue');

// Mobile overscroll guard: once a scroller reaches its top or bottom
// edge, further swiping in that direction is swallowed, so the page
// does not rubber-band, chain into a parent scroller, or trigger
// pull-to-refresh. Inner scrollers (popup cards, the reels viewer,
// text areas) are judged by their own edges; everything else is
// judged against the document. CSS overscroll-behavior alone cannot
// do this for the root scroller on iOS, hence the touch handler.
let touchStartY = 0;

document.addEventListener(
    'touchstart',
    (event) => {
        touchStartY = event.touches[0].clientY;
    },
    { passive: true },
);

document.addEventListener(
    'touchmove',
    (event) => {
        const dy = event.touches[0].clientY - touchStartY;
        if (dy === 0) {
            return;
        }

        let el = event.target instanceof Element ? event.target : null;
        while (el && el !== document.body) {
            const style = window.getComputedStyle(el);
            if (/(auto|scroll)/.test(style.overflowY) && el.scrollHeight > el.clientHeight) {
                const innerAtTop = el.scrollTop <= 0;
                const innerAtBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 1;
                if ((innerAtTop && dy > 0) || (innerAtBottom && dy < 0)) {
                    event.preventDefault();
                }
                return;
            }
            el = el.parentElement;
        }

        const doc = document.documentElement;
        const atTop = window.scrollY <= 0;
        const atBottom = window.scrollY + window.innerHeight >= doc.scrollHeight - 1;
        if ((atTop && dy > 0) || (atBottom && dy < 0)) {
            event.preventDefault();
        }
    },
    { passive: false },
);

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
