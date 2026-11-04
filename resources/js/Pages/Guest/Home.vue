<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, Gift, LockKeyhole, LogOut, Wallet } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import { post } from '../../lib/api';

const props = defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
    counts: {
        type: Object,
        default: () => ({ locked: 0, total: 0, wishes: 0 }),
    },
});

const signingOut = ref(false);

const lockedPercent = computed(() => {
    const total = props.counts?.total || 0;
    if (!total) return 0;
    return Math.min(100, Math.round(((props.counts?.locked || 0) / total) * 100));
});

const partyDate = computed(() => {
    if (!props.party?.date) return '';
    const parsed = new Date(`${props.party.date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return props.party.date;
    return parsed.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
});

const firstName = computed(() => (props.guest?.display || '').split(' ')[0] || 'there');

async function signOut() {
    signingOut.value = true;
    try {
        await post('guest_logout');
    } catch {
        // Leaving matters more than a server hiccup here.
    } finally {
        router.visit('/');
    }
}
</script>

<template>
    <Head title="My party home" />
    <GuestLayout dock wide>
        <!-- Mascot row in the page flow at the top of the dashboard, so it
             never floats over content or the dock. -->
        <Mascot :message="`Welcome back, ${firstName}!`" />

        <div class="dash-grid slide-in" style="margin-top: 26px">
            <div class="card dash-span">
                <span class="section-label">Your party</span>
                <h1 class="card-title" style="margin-bottom: 6px">{{ party?.name || 'Christmas Party' }}</h1>
                <p class="card-subtitle" style="margin: 0">
                    Hi {{ guest?.nickname || guest?.display }}, you are in. Your person
                    stays a secret now, even from the host.
                </p>
                <p v-if="partyDate" class="dash-meta">
                    <CalendarDays :size="16" style="vertical-align: -3px" />
                    {{ partyDate }}
                </p>
            </div>

            <div class="card">
                <span class="section-label">
                    <LockKeyhole :size="12" style="vertical-align: -1px" />
                    Names locked
                </span>
                <p class="dash-value">{{ counts?.locked || 0 }} of {{ counts?.total || 0 }}</p>
                <p class="dash-sub">names locked so far</p>
                <div class="progress-track" style="margin-top: 14px">
                    <div class="progress-fill" :style="{ width: `${lockedPercent}%` }"></div>
                </div>
            </div>

            <div class="card">
                <span class="section-label">
                    <Wallet :size="12" style="vertical-align: -1px" />
                    Gift budget
                </span>
                <p class="dash-value">{{ party?.budget || 'Not set' }}</p>
                <p class="dash-sub">the guide for every gift at this party</p>
            </div>

            <div class="card">
                <span class="section-label">
                    <Gift :size="12" style="vertical-align: -1px" />
                    My wishes
                </span>
                <p class="dash-value">{{ counts?.wishes || 0 }}</p>
                <p class="dash-sub">wishes on your own list</p>
                <Link href="/guest/gifts" class="btn btn-secondary" style="margin-top: 14px">
                    <Gift :size="15" />
                    <span>Open my wishlist</span>
                </Link>
            </div>

            <div class="card dash-span">
                <span class="section-label">Done for now?</span>
                <p class="dash-sub" style="margin-bottom: 16px">
                    Signing out does not unlock your name or undo your reveal. Just
                    enter your PIN again when you come back.
                </p>
                <button type="button" class="btn btn-secondary" :disabled="signingOut" @click="signOut">
                    <LogOut :size="16" />
                    <span>{{ signingOut ? 'Signing out...' : 'Sign out' }}</span>
                </button>
            </div>
        </div>
    </GuestLayout>
</template>

<style scoped>
.dash-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

@media (min-width: 640px) {
    .dash-grid {
        grid-template-columns: 1fr 1fr;
    }

    .dash-span {
        grid-column: 1 / -1;
    }
}

@media (min-width: 1024px) {
    .dash-grid {
        grid-template-columns: 1fr 1fr 1fr;
    }
}

.dash-value {
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1.1;
    margin: 10px 0 4px;
}

.dash-sub {
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.5;
}

.dash-meta {
    color: var(--text-secondary);
    margin: 14px 0 0;
    font-weight: 600;
}
</style>
