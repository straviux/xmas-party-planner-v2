<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Dices, House, ListChecks, Plus, RefreshCw, Vote } from 'lucide-vue-next';

const props = defineProps({
    dock: {
        type: Boolean,
        default: false,
    },
    wide: {
        type: Boolean,
        default: false,
    },
    active: {
        type: String,
        default: '',
    },
});

const page = usePage();

const current = computed(() => {
    if (props.active) return props.active;
    const url = page.url || '';
    if (url.startsWith('/guest/kringle')) return 'kringle';
    if (url.startsWith('/guest/wishlists')) return 'wishlists';
    if (url.startsWith('/guest/vote')) return 'vote';
    return 'home';
});

// Pull-to-refresh (owner's ask, 2026-11-09). The fixed-frame shell
// suppresses the browser's own pull-to-refresh (overscroll-behavior:
// none), so the gesture is reimplemented here: pulling down from the
// very top of the shell drags a spinner badge in, and releasing past
// the threshold reloads the page, which is what guests expect the
// gesture to do. Only a mostly-vertical pull that starts at scroll
// position zero counts, and never from the dock or a form field.
const shellRef = ref(null);
const pull = ref(0);
const refreshing = ref(false);
const snapping = ref(false);
let tracking = false;
let startX = 0;
let startY = 0;

const PULL_TRIGGER = 46;

function onTouchStart(event) {
    const shell = shellRef.value;
    if (refreshing.value || !shell || event.touches.length !== 1) {
        tracking = false;
        return;
    }
    if (shell.scrollTop > 0 || event.target.closest('nav, input, textarea, select')) {
        tracking = false;
        return;
    }
    tracking = true;
    snapping.value = false;
    startX = event.touches[0].clientX;
    startY = event.touches[0].clientY;
}

function onTouchMove(event) {
    if (!tracking) return;
    const dy = event.touches[0].clientY - startY;
    const dx = event.touches[0].clientX - startX;
    if (dy <= 0 || Math.abs(dx) > dy) {
        pull.value = 0;
        return;
    }
    pull.value = Math.min(76, dy * 0.6);
    if (event.cancelable) event.preventDefault();
}

function onTouchEnd() {
    if (!tracking) return;
    tracking = false;
    snapping.value = true;
    if (pull.value >= PULL_TRIGGER) {
        refreshing.value = true;
        pull.value = 52;
        window.setTimeout(() => window.location.reload(), 400);
    } else {
        pull.value = 0;
    }
}

onMounted(() => {
    const shell = shellRef.value;
    if (!shell) return;
    shell.addEventListener('touchstart', onTouchStart, { passive: true });
    shell.addEventListener('touchmove', onTouchMove, { passive: false });
    shell.addEventListener('touchend', onTouchEnd);
    shell.addEventListener('touchcancel', onTouchEnd);
});

onBeforeUnmount(() => {
    const shell = shellRef.value;
    if (!shell) return;
    shell.removeEventListener('touchstart', onTouchStart);
    shell.removeEventListener('touchmove', onTouchMove);
    shell.removeEventListener('touchend', onTouchEnd);
    shell.removeEventListener('touchcancel', onTouchEnd);
});
</script>

<template>
    <!-- scroll-region: the shell, not the document, scrolls on guest
         pages, so Inertia must reset/restore THIS element's position
         on navigations (its window-level handling would do nothing). -->
    <div ref="shellRef" class="guest-shell" scroll-region>
        <div
            class="ptr-indicator"
            :class="{ refreshing, snapping }"
            :style="{
                transform: `translate(-50%, ${pull - 58}px)`,
                opacity: pull > 4 || refreshing ? 1 : 0,
            }"
            aria-hidden="true"
        >
            <RefreshCw :size="19" :style="refreshing ? null : { transform: `rotate(${pull * 3.4}deg)` }" />
        </div>

        <main class="guest-container" :class="{ wide, 'has-dock': dock }">
            <slot />
        </main>

        <nav v-if="dock" class="dock" aria-label="Guest navigation">
            <Link href="/guest" class="dock-item" :class="{ active: current === 'home' }">
                <House :size="22" />
                <span>Home</span>
            </Link>
            <Link href="/guest/kringle" class="dock-item" :class="{ active: current === 'kringle' }">
                <Dices :size="22" />
                <span>Monito</span>
            </Link>
            <Link href="/guest/wishlists?add=1" class="dock-centre-wrap" aria-label="Add a wishlist item">
                <span class="dock-centre">
                    <Plus :size="20" :stroke-width="2.6" />
                </span>
                <span class="dock-centre-label">Add a wish</span>
            </Link>
            <Link href="/guest/wishlists" class="dock-item" :class="{ active: current === 'wishlists' }">
                <ListChecks :size="22" />
                <span>Wishlists</span>
            </Link>
            <Link href="/guest/vote" class="dock-item" :class="{ active: current === 'vote' }">
                <Vote :size="22" />
                <span>Vote</span>
            </Link>
        </nav>
    </div>
</template>
