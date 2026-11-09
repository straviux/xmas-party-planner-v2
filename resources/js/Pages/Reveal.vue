<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, Hourglass, House, LogOut, PenLine, TriangleAlert } from 'lucide-vue-next';
import GuestLayout from '../Components/GuestLayout.vue';
import RevealStrip from '../Components/RevealStrip.vue';
import GiftReveal from '../Components/GiftReveal.vue';
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

// phase: idle -> tearing (committed, strip sliding open) -> done
//             -> waiting (revealed, but the recipient has no secret name
//                yet, so there is nothing to slide open) | back to idle
//                on a retryable error
const phase = ref('idle');
const recipient = ref(null);
const errorText = ref('');
const errorAction = ref(''); // '' | 'home'
const signingOut = ref(false);
// Bumped after a failed reveal call so the strip springs its cover back
// and can be slid again.
const failSignal = ref(0);

// Fired by the strip when the guest commits a slide gesture. The
// recipient name only exists on this page after this call returns it, so
// no amount of pre-commit dragging (which is purely visual) can show or
// consume it.
async function tear() {
    if (phase.value !== 'idle') return;
    errorText.value = '';
    errorAction.value = '';
    phase.value = 'tearing';

    let body = null;
    let failure = null;
    try {
        body = await post('reveal');
    } catch (err) {
        failure = err;
    }

    if (body) {
        const revealed = body.recipient || null;
        if (revealed && revealed.secret_name) {
            recipient.value = revealed;
            // The strip sees `recipient` arrive and runs the slow
            // completion slide; onStripDone flips phase to 'done' once
            // it finishes.
        } else {
            // The reveal itself is done, but the recipient has not
            // picked a secret name yet: there is no name to hide under
            // the strip, so the page switches to the waiting state and
            // the strip never receives a recipient.
            phase.value = 'waiting';
        }
        return;
    }

    // Failure: bump the signal so the strip springs its cover back.
    failSignal.value++;
    phase.value = 'idle';
    if (!failure) return;
    if (failure.code === 'already_claimed') {
        errorText.value = 'You have already revealed your recipient. Head to your home page.';
        errorAction.value = 'home';
    } else if (failure.code === 'not_claimed') {
        toastError('Enter your name and PIN first, then come back to reveal.');
        router.visit('/enter');
    } else if (failure.code === 'pin_incorrect') {
        errorText.value = 'Something did not check out. Try sliding again.';
    } else {
        errorText.value = failure.message || 'The reveal did not work. Try sliding again.';
    }
}

// The strip finished its completion slide and the name is uncovered.
function onStripDone() {
    phase.value = 'done';
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
            <h1 class="card-title">Your recipient is hidden here</h1>
            <p class="card-subtitle" style="margin-bottom: 8px">
                Hi {{ guest?.display }}. When you are ready, slide the strip below
                to see the secret name of the person you are buying for. Make
                sure nobody is looking over your shoulder.
            </p>

            <!-- Slide-to-reveal control: the secret name lives inside the
                 strip, under its sliding cover (see RevealStrip). Hidden
                 entirely in the waiting state, where the recipient has
                 no secret name to uncover yet. -->
            <template v-if="phase !== 'waiting'">
                <RevealStrip
                    :recipient="recipient"
                    :fail-signal="failSignal"
                    @commit="tear"
                    @done="onStripDone"
                />

                <p v-if="phase === 'idle'" class="muted small" style="margin: 10px 0 0; text-align: center">
                    Drag the strip across, or tap it, and their secret name appears inside the strip.
                </p>
            </template>

            <p v-if="errorText" class="error-text" style="margin-top: 18px">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ errorText }}</span>
            </p>
            <Link v-if="errorAction === 'home'" href="/guest" class="btn btn-primary btn-block" style="margin-top: 6px">
                <House :size="18" />
                <span>Go to my home</span>
            </Link>

            <!-- Revealed, but the recipient has no secret name yet:
                 the result block keeps the success shape, with a
                 placeholder standing in for the name until they pick
                 one. -->
            <div v-if="phase === 'waiting'" class="pop-in" style="margin-top: 24px">
                <div class="result-card">
                    <Hourglass :size="30" style="color: var(--text-tertiary)" />
                    <h2 class="waiting-title">Your recipient is still choosing</h2>
                    <p class="buying-for">
                        <span class="buying-for-label">You are buying for</span>
                        <span class="buying-for-placeholder" role="img" aria-label="Secret name not picked yet">???</span>
                    </p>
                    <p class="muted" style="margin: 0; line-height: 1.55">
                        Your person has not picked their secret name yet. Check
                        again later from your home page, under Show my
                        recipient again.
                    </p>
                </div>

                <Link href="/guest" class="btn btn-primary btn-block">
                    <House :size="18" />
                    <span>Go to my home</span>
                </Link>
            </div>

            <!-- After the reveal: warning, next steps. There is
                 deliberately no wishlist link here: wishlists sit under
                 real names, and following one would connect this secret
                 name to a real person. -->
            <div v-if="phase === 'done' && recipient" class="pop-in" style="margin-top: 24px">
                <!-- One-time gift reveal: this block only follows a
                     fresh reveal in this visit (the strip's completion
                     slide just uncovered the name), so the unwrap plays
                     exactly once, at the reveal moment. There is no
                     replay path: the reveal itself can never run
                     again, and later checks go through the home page's
                     reveal-again popup, which has no gift. -->
                <GiftReveal :name="recipient.secret_name" />

                <p class="warning-card">
                    <TriangleAlert :size="19" style="flex: none; margin-top: 1px" />
                    <span>
                        <strong>Write this down now.</strong>
                        You can also check it again from your home page, under
                        Show my recipient again.
                    </span>
                </p>

                <div class="stack">
                    <Link href="/guest" class="btn btn-primary btn-block">
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
            Tip: jot the secret name down somewhere private before you leave this page.
        </p>
    </GuestLayout>
</template>

<style scoped>
.result-card {
    border: 1px solid var(--card-border);
    background: var(--glass);
    border-radius: 18px;
    padding: 16px 20px;
    text-align: center;
    margin-bottom: 16px;
}

.waiting-title {
    font-size: 1.15rem;
    font-weight: 800;
    margin: 10px 0 8px;
}

/* The "You are buying for" result block, shared in shape with the
   success state (and with the reveal-again popup in Guest Home, which
   carries the same classes): the label matches RevealStrip's
   under-layer label, and the placeholder sits at the revealed name's
   type scale but muted and italic, so it can never be mistaken for an
   actual secret name. */
.buying-for {
    margin: 14px 0 12px;
}

.buying-for-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
}

.buying-for-placeholder {
    display: block;
    font-size: 1.38rem;
    font-weight: 800;
    font-style: italic;
    letter-spacing: 0.04em;
    line-height: 1.15;
    color: var(--text-tertiary);
    margin-top: 2px;
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
</style>
