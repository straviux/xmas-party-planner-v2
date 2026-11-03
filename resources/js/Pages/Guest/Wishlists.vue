<script setup>
import { Head } from '@inertiajs/vue3';
import { ListChecks } from 'lucide-vue-next';
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
    <Head title="Everyone's wishlists" />
    <GuestLayout dock wide>
        <div class="card slide-in">
            <span class="section-label">Wishlists</span>
            <h1 class="card-title">Everyone's wishlists</h1>
            <div class="empty-state">
                <ListChecks :size="40" />
                <p class="empty-state-title">Nothing here yet</p>
                <p class="empty-state-text">
                    Wishlists will appear here once guests start adding wishes.
                </p>
            </div>
        </div>
    </GuestLayout>
</template>
