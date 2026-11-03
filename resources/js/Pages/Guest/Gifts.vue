<script setup>
import { Head } from '@inertiajs/vue3';
import { Gift } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';

defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <Head title="My wishlist" />
    <GuestLayout dock wide>
        <div class="card slide-in">
            <span class="section-label">My wishlist</span>
            <h1 class="card-title">My gifts</h1>
            <div class="empty-state">
                <Gift :size="40" />
                <p class="empty-state-title">No wishes yet</p>
                <p class="empty-state-text">
                    Use the gold + button to add your first wish, so your Secret
                    Santa knows what to look for.
                </p>
            </div>
        </div>
    </GuestLayout>
</template>
