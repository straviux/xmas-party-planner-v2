<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { QrCode, UserRound } from 'lucide-vue-next';
import GuestLayout from '../Components/GuestLayout.vue';
import Mascot from '../Components/Mascot.vue';

const props = defineProps({
    code: {
        type: String,
        default: null,
    },
    inviteCode: {
        type: String,
        default: null,
    },
    party: {
        type: Object,
        default: null,
    },
});

const inviteFromUrl = ref('');

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    inviteFromUrl.value = params.get('code') || '';
});

// An invite code (prop or ?code=) is accepted and carried to the entry
// page, but the landing layout stays identical either way.
const enterHref = () => {
    const code = props.code || props.inviteCode || inviteFromUrl.value;
    return code ? `/enter?code=${encodeURIComponent(code)}` : '/enter';
};
</script>

<template>
    <Head title="Welcome" />
    <GuestLayout>
        <Mascot message="Ho ho ho! Ready to party?" />

        <div class="card slide-in" style="margin-top: 26px; text-align: center">
            <span class="section-label">Secret Santa HQ</span>
            <h1 class="card-title">Christmas Party Planner</h1>
            <p class="card-subtitle" style="margin-bottom: 24px">
                Find your name, reveal your Secret Santa in secret, and share your
                wishlist with everyone at the party.
            </p>

            <div class="stack">
                <Link :href="enterHref()" class="btn btn-primary btn-block">
                    <UserRound :size="19" />
                    <span>I'm a guest</span>
                </Link>
                <Link :href="enterHref()" class="btn btn-secondary btn-block">
                    <QrCode :size="19" />
                    <span>Scan QR code</span>
                </Link>
            </div>

            <Link href="/host" class="host-signin-link">Host sign-in</Link>
        </div>
    </GuestLayout>
</template>
