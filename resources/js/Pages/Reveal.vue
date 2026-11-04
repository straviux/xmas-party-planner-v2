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

// phase: idle -> tearing (committed, sliding open) -> done | back to idle on a retryable error
const phase = ref('idle');
const recipient = ref(null);
const budget = ref(null);
const errorText = ref('');
const errorAction = ref(''); // '' | 'home'
const signingOut = ref(false);

// Slide state: progress 0..1 drives the cover. The recipient name lives in
// the layer under the cover, inside the track, so it is uncovered exactly
// as far as the cover has slid. The name only exists on this page after
// the reveal call returns it, so no amount of pre-commit dragging (which
// is purely visual) can show or consume it.
const progress = ref(0);
const animMs = ref(0);
const dragging = ref(false);
const trackEl = ref(null);
let dragStartX = 0;
let dragStartProgress = 0;
let dragMoved = false;

const COMMIT_AT = 0.72;

const coverStyle = computed(() => ({
    transform: `translateX(${progress.value * 102}%)`,
    transition: dragging.value ? 'none' : `transform ${animMs.value}ms cubic-bezier(0.22, 0.9, 0.3, 1)`,
}));

function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function onPointerDown(event) {
    if (phase.value !== 'idle') return;
    dragging.value = true;
    dragMoved = false;
    dragStartX = event.clientX;
    dragStartProgress = progress.value;
    trackEl.value?.setPointerCapture?.(event.pointerId);
}

function onPointerMove(event) {
    if (!dragging.value || phase.value !== 'idle') return;
    const rect = trackEl.value?.getBoundingClientRect();
    if (!rect || rect.width === 0) return;
    const dx = event.clientX - dragStartX;
    if (Math.abs(dx) > 6) dragMoved = true;
    progress.value = Math.min(1, Math.max(0, dragStartProgress + dx / rect.width));
}

async function onPointerUp() {
    if (!dragging.value) return;
    dragging.value = false;
    if (phase.value !== 'idle') return;
    // A tap (no real drag) commits too, like the old tear tap.
    if (!dragMoved || progress.value >= COMMIT_AT) {
        await reveal();
    } else {
        // Released early: spring back, the reveal is not consumed.
        animMs.value = reducedMotion() ? 40 : 280;
        progress.value = 0;
    }
}

function onPointerCancel() {
    if (!dragging.value) return;
    dragging.value = false;
    if (phase.value === 'idle') {
        animMs.value = reducedMotion() ? 40 : 280;
        progress.value = 0;
    }
}

async function reveal() {
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
        recipient.value = body.recipient || null;
        budget.value = body.budget ?? props.party?.budget ?? null;
        // Slow final slide: the name sits under the cover and is uncovered
        // progressively as the cover slides away.
        animMs.value = reducedMotion() ? 60 : 1250;
        progress.value = 1;
        await wait(animMs.value);
        phase.value = 'done';
        return;
    }

    // Failure: the cover slides back so it can be tried again.
    animMs.value = reducedMotion() ? 40 : 300;
    progress.value = 0;
    phase.value = 'idle';
    if (!failure) return;
    if (failure.code === 'already_claimed') {
        errorText.value = 'You have already revealed your person. Head to your home page.';
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
                Hi {{ guest?.display }}. When you are ready, slide the strip below.
                Your person is shown exactly once, so make sure nobody is looking
                over your shoulder.
            </p>

            <!-- Slide-to-reveal control: the name lives inside the track,
                 under the sliding cover. -->
            <div
                ref="trackEl"
                class="tear-track"
                :class="{ done: phase === 'done' }"
                role="button"
                tabindex="0"
                :aria-disabled="phase !== 'idle'"
                aria-label="Slide to reveal your Secret Santa person"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerCancel"
                @keydown.enter.prevent="reveal"
                @keydown.space.prevent="reveal"
            >
                <!-- Under-layer: what the cover uncovers -->
                <span class="tear-under">
                    <template v-if="recipient">
                        <span class="tear-under-label">You are buying for</span>
                        <span class="tear-under-name">
                            {{ recipient.display }}<span v-if="recipient.nickname" class="tear-under-nick"> “{{ recipient.nickname }}”</span>
                        </span>
                    </template>
                    <template v-else>
                        <Gift :size="26" class="tear-under-icon" />
                        <span class="tear-under-hint">Someone is wrapped up in here</span>
                    </template>
                </span>

                <!-- Sliding cover with the drag handle -->
                <span class="tear-cover" :style="coverStyle">
                    <span class="tear-label">{{ phase === 'done' ? 'Revealed' : 'Slide to reveal' }}</span>
                    <span class="tear-knob">
                        <ArrowRight :size="24" :stroke-width="2.6" />
                    </span>
                </span>
            </div>

            <p v-if="phase === 'idle'" class="muted small" style="margin: 10px 0 0; text-align: center">
                Drag the strip across, or tap it, and your person's name appears inside the strip.
            </p>

            <p v-if="errorText" class="error-text" style="margin-top: 18px">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ errorText }}</span>
            </p>
            <Link v-if="errorAction === 'home'" href="/guest" class="btn btn-primary btn-block" style="margin-top: 6px">
                <House :size="18" />
                <span>Go to my home</span>
            </Link>

            <!-- After the reveal: budget, warning, next steps -->
            <div v-if="phase === 'done' && recipient" class="pop-in" style="margin-top: 24px">
                <div v-if="budget" class="result-card">
                    <p class="result-budget">
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
    height: 84px;
    margin-top: 22px;
    border: 2px dashed rgba(255, 255, 255, 0.38);
    border-radius: 999px;
    background:
        repeating-linear-gradient(
            -45deg,
            rgba(255, 255, 255, 0.05) 0 14px,
            rgba(255, 255, 255, 0.015) 14px 28px
        ),
        rgba(255, 255, 255, 0.07);
    cursor: grab;
    user-select: none;
    -webkit-user-select: none;
    touch-action: none;
    overflow: hidden;
}

.tear-track.done {
    cursor: default;
}

/* The uncovered layer: the recipient name after the reveal, a wrapped
   placeholder before it. */
.tear-under {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 2px;
    padding: 0 26px;
    pointer-events: none;
}

.tear-under-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
}

.tear-under-name {
    font-size: 1.38rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    line-height: 1.15;
    color: var(--text-primary, #ffffff);
    white-space: nowrap;
}

.tear-under-nick {
    font-size: 1.02rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.tear-under-icon {
    color: var(--text-tertiary);
    align-self: center;
}

.tear-under-hint {
    align-self: center;
    color: var(--text-tertiary);
    font-size: 0.92rem;
}

/* The sliding cover. */
.tear-cover {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: linear-gradient(135deg, #5936b4 0%, #362a84 100%);
    box-shadow: -12px 0 28px rgba(0, 0, 0, 0.35);
    pointer-events: none;
    will-change: transform;
}

.tear-label {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-right: 46px;
    font-weight: 800;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    font-size: 0.85rem;
    color: #ffffff;
    pointer-events: none;
}

.tear-knob {
    position: absolute;
    top: 50%;
    right: 12px;
    width: 54px;
    height: 54px;
    margin-top: -27px;
    border-radius: 50%;
    background: linear-gradient(135deg, #a3e635 0%, #84cc16 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 22px rgba(132, 204, 22, 0.45);
    pointer-events: none;
}

.result-card {
    border: 1px solid var(--card-border);
    background: var(--glass);
    border-radius: 18px;
    padding: 16px 20px;
    text-align: center;
    margin-bottom: 16px;
}

.result-budget {
    color: var(--text-secondary);
    margin: 0;
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
