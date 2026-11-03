<script setup>
import { Head } from '@inertiajs/vue3';
import { Vote } from 'lucide-vue-next';
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
    <Head title="Vote" />
    <GuestLayout dock wide>
        <div class="card slide-in">
            <span class="section-label">Polls</span>
            <h1 class="card-title">Vote</h1>
            <div class="empty-state">
                <Vote :size="40" />
                <p class="empty-state-title">No polls yet</p>
                <p class="empty-state-text">
                    When your host adds a poll, it will show up here for you to vote.
                </p>
            </div>
        </div>
    </GuestLayout>
</template>
