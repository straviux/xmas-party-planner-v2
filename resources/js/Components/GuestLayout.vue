<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Gift, House, ListChecks, Plus, Star, Vote } from 'lucide-vue-next';

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
    if (url.startsWith('/guest/gifts')) return 'gifts';
    if (url.startsWith('/guest/wishlists')) return 'wishlists';
    if (url.startsWith('/guest/vote')) return 'vote';
    return 'home';
});
</script>

<template>
    <!-- scroll-region: the shell, not the document, scrolls on guest
         pages, so Inertia must reset/restore THIS element's position
         on navigations (its window-level handling would do nothing). -->
    <div class="guest-shell" scroll-region>
        <main class="guest-container" :class="{ wide, 'has-dock': dock }">
            <slot />
        </main>

        <nav v-if="dock" class="dock" aria-label="Guest navigation">
            <Link href="/guest" class="dock-item" :class="{ active: current === 'home' }">
                <House :size="22" />
                <span>Home</span>
            </Link>
            <Link href="/guest/gifts" class="dock-item" :class="{ active: current === 'gifts' }">
                <Gift :size="22" />
                <span>My wishes</span>
            </Link>
            <Link href="/guest/gifts?add=1" class="dock-centre-wrap" aria-label="Add a wishlist item">
                <span class="dock-centre">
                    <Star :size="21" />
                    <Plus class="dock-centre-plus" :size="12" :stroke-width="3.2" />
                </span>
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
