<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { ChartColumn } from 'lucide-vue-next';
import HostLayout from '../../Components/HostLayout.vue';
import SignInGate from './SignInGate.vue';

const props = defineProps({
    authed: {
        type: Boolean,
        default: false,
    },
    username: {
        type: String,
        default: '',
    },
});

const partyName = ref('');
</script>

<template>
    <Head title="Polls" />
    <SignInGate v-if="!authed" />
    <HostLayout
        v-else
        :party-name="partyName"
        :username="props.username"
        title="Polls"
        subtitle="Party voting, like choosing this year's Christmas theme."
    >
        <section class="host-panel empty-state">
            <ChartColumn :size="40" />
            <h2 class="empty-state-title">No polls yet</h2>
            <p class="empty-state-text">
                Polls arrive in a later build step. When they do, you will create
                polls here, and guests will vote once per poll and see counts and
                percentages only — never who voted for what.
            </p>
        </section>
    </HostLayout>
</template>
