<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, CircleAlert, Gift, House, LogOut, PenLine, TriangleAlert } from 'lucide-vue-next';
import GuestLayout from '../Components/GuestLayout.vue';
import { post } from '../lib/api';
import { toastError } from '../lib/toast';

const props = defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
});

// phase: idle -> tearing -> done | back to idle on a retryable error
const phase = ref('idle');
const recipient = ref(null);
const budget = ref(null);
const errorText = ref('');
const errorAction = ref(''); // '' | 'home'
const signingOut = ref(false);

const torn = computed(() => phase.value === 'tearing' || phase.value === 'done');

function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function tear() {
    if (phase.value === 'tearing' || phase.value === 'done') return;
    errorText.value = '';
    errorAction.value = '';
    phase.value = 'tearing';

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const animationDone = wait(reduced ? 60 : 480);

    let body = null;
    let failure = null;
    try {
        body = await post('reveal');
    } catch (err) {
        failure = err;
    }
    await animationDone;

    if (body) {
        recipient.value = body.recipient || null;
        budget.value = body.budget ?? props.party?.budget ?? null;
        phase.value = 'done';
        return;
    }

    // Failure: the strip slides back so it can be tried again.
    phase.value = 'idle';
    if (!failure) return;
    if (failure.code === 'already_claimed') {
        errorText.value = 'You have already revealed your person. Head to your home page.';
        errorAction.value = 'home';
    } else if (failure.code === 'not_claimed') {
        toastError('Enter your name and PIN first, then come back to reveal.');
        router.visit('/enter');
    } else if (failure.code === 'pin_incorrect') {
        errorText.value = 'Something did not check out. Try tearing again.';
    } else {
        errorText.value = failure.message || 'The reveal did not work. Try tearing again.';
    }
}

async function signOut() {
    signingOut.value = true;
    try {
        await post('guest_logout');
    } catch {
        // Signing out locally matters more than a server hiccup here.
    } finally {
        router.visit('/');
    }
}
</script>

<template>
    <Head title="Your reveal" />
    <GuestLayout>
        <div class="card slide-in">
            <span class="section-label">Secret Santa reveal</span>
            <h1 class="card-title">Your person is hidden here</h1>
            <p class="card-subtitle" style="margin-bottom: 8px">
                Hi {{ guest?.display }}. When you are ready, tear the strip below.
                Your person is shown exactly once, so make sure nobody is looking
                over your shoulder.
            </p>

            <!-- Tear control -->
            <div
                class="tear-track"
                :class="{ torn, done: phase === 'done' }"
                role="button"
                tabindex="0"
                :aria-disabled="phase === 'tearing' || phase === 'done'"
                aria-label="Tear to reveal your Secret Santa person"
                @click="tear"
                @keydown.enter.prevent="tear"
                @keydown.space.prevent="tear"
            >
                <span class="tear-label">{{ phase === 'done' ? 'Revealed' : 'Tear' }}</span>
                <span class="tear-knob">
                    <ArrowRight :size="24" :stroke-width="2.6" />
                </span>
            </div>

            <p v-if="errorText" class="error-text" style="margin-top: 18px">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ errorText }}</span>
            </p>
            <Link v-if="errorAction === 'home'" href="/guest" class="btn btn-primary btn-block" style="margin-top: 6px">
                <House :size="18" />
                <span>Go to my home</span>
            </Link>

            <!-- Revealed result -->
            <div v-if="phase === 'done' && recipient" class="pop-in" style="margin-top: 24px">
                <div class="result-card">
                    <span class="section-label">You are buying for</span>
                    <p class="result-name">{{ recipient.display }}</p>
                    <p v-if="recipient.nickname" class="result-sub">“{{ recipient.nickname }}”</p>
                    <p v-if="budget" class="result-budget">
                        <Gift :size="17" style="vertical-align: -3px" />
                        Gift budget: <strong>{{ budget }}</strong>
                    </p>
                </div>

                <p class="warning-card">
                    <TriangleAlert :size="19" style="flex: none; margin-top: 1px" />
                    <span>
                        <strong>Write this down now.</strong>
                        You will not be shown this again.
                    </span>
                </p>

                <div class="stack">
                    <Link href="/guest/wishlists" class="btn btn-primary btn-block">
                        <Gift :size="18" />
                        <span>See their wishlist</span>
                    </Link>
                    <Link href="/guest" class="btn btn-secondary btn-block">
                        <House :size="18" />
                        <span>Go to my home</span>
                    </Link>
                </div>
            </div>

            <div style="text-align: center; margin-top: 20px">
                <button type="button" class="text-btn" :disabled="signingOut" @click="signOut">
                    <LogOut :size="13" style="vertical-align: -2px" />
                    Not you? Sign out
                </button>
            </div>
        </div>

        <p class="muted small" style="text-align: center; margin-top: 18px">
            <PenLine :size="14" style="vertical-align: -2px" />
            Tip: jot the name down somewhere private before you leave this page.
        </p>
    </GuestLayout>
</template>

<style scoped>
.tear-track {
    position: relative;
    height: 68px;
    margin-top: 22px;
    border: 2px dashed rgba(255, 255, 255, 0.38);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.07);
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    touch-action: manipulation;
}

.tear-track.done {
    cursor: default;
}

.tear-label {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-left: 52px;
    font-weight: 800;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    font-size: 0.85rem;
    color: var(--text-secondary);
    pointer-events: none;
}

.tear-knob {
    position: absolute;
    top: 50%;
    left: 6px;
    width: 52px;
    height: 52px;
    margin-top: -26px;
    border-radius: 50%;
    background: linear-gradient(135deg, #a3e635 0%, #84cc16 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 22px rgba(132, 204, 22, 0.45);
    transition: left 0.45s ease;
    pointer-events: none;
}

.tear-track.torn .tear-knob {
    left: calc(100% - 58px);
}

.result-card {
    border: 1px solid var(--card-border);
    background: var(--glass);
    border-radius: 18px;
    padding: 24px 20px;
    text-align: center;
    margin-bottom: 16px;
}

.result-name {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    line-height: 1.15;
    margin: 6px 0 0;
}

.result-sub {
    color: var(--text-secondary);
    margin: 6px 0 0;
    font-size: 1.02rem;
}

.result-budget {
    color: var(--text-secondary);
    margin: 16px 0 0;
}

.warning-card {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    border: 1px solid rgba(255, 196, 86, 0.4);
    background: rgba(255, 176, 32, 0.12);
    color: #ffd88a;
    border-radius: 14px;
    padding: 13px 15px;
    font-size: 0.92rem;
    line-height: 1.5;
    margin: 0 0 20px;
    text-align: left;
}

.text-btn {
    background: none;
    border: none;
    color: var(--text-tertiary);
    font-size: 0.88rem;
    text-decoration: underline;
    cursor: pointer;
    padding: 6px;
}

@media (prefers-reduced-motion: reduce) {
    .tear-knob {
        transition: none !important;
    }
}
</style>
