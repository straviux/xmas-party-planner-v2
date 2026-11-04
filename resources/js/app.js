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

// Double-tap guard: a second touchend landing within 300ms of the
// previous one is swallowed, so double-tap zoom stays dead even in
// scanner and in-app webviews that ignore the viewport meta and the
// touch-action CSS above. Taps inside form fields are exempt (and do
// not start the clock), so fast typing and PIN entry are never
// blocked; single taps, drags (the reveal strip), and scrolling are
// unaffected.
let lastTouchEndAt = 0;

document.addEventListener(
    'touchend',
    (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target && target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }

        const now = Date.now();
        if (now - lastTouchEndAt <= 300) {
            event.preventDefault();
        }
        lastTouchEndAt = now;
    },
    { passive: false },
);

// Pinch-zoom guard: the viewport meta already forbids zooming, but
// iOS Safari and some in-app webviews ignore it, so any touchmove
// with two fingers down is swallowed outright, and Safari's own
// gesture events are cancelled at the source. Single-finger
// scrolling and dragging (the reveal strip, the reels viewer) never
// have two touches down, so they are unaffected.
document.addEventListener(
    'touchmove',
    (event) => {
        if (event.touches.length > 1) {
            event.preventDefault();
        }
    },
    { passive: false },
);

document.addEventListener('gesturestart', (event) => event.preventDefault());
document.addEventListener('gesturechange', (event) => event.preventDefault());

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
