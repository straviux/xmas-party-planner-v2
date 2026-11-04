<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRight, Gift } from 'lucide-vue-next';

// Slide-to-reveal strip shared by the Reveal page and the Guest Home
// "See my person again" popup.
//
// The recipient name lives in the layer under the sliding cover, inside
// the track, so it is uncovered exactly as far as the cover has slid.
// Dragging is purely visual until the guest commits (release at >= 0.72,
// a tap, or Enter/Space).
//
// Contract:
// - recipient (prop): null shows the wrapped placeholder under the cover;
//   { display, nickname } shows the "You are buying for" name layer.
// - While `recipient` is null, a commit emits `commit` and the strip
//   HOLDS the cover at its release position (state "awaiting") until the
//   parent either sets `recipient` (the slow completion slide runs, then
//   `done` is emitted) or bumps `failSignal` (the cover springs back and
//   the strip is idle again).
// - While `recipient` is already set, a commit runs the completion slide
//   locally with no `commit` emit (the popup mode: the strip is a pure
//   presentation reveal), then emits `done`.
// - failSignal (prop) is also the parent's reset lever: ANY change to it,
//   in any state, resets the strip to a fresh, covered, idle state. A
//   parent that reuses one mounted strip (for example across popup opens)
//   can bump it to start covered again.
// - After `done` the strip is inert.
const props = defineProps({
    recipient: {
        type: Object,
        default: null,
    },
    failSignal: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['commit', 'done']);

// state: idle -> awaiting (committed, parent call in flight) -> sliding -> done
//             \-> sliding (recipient already set) -> done
const state = ref('idle');
const progress = ref(0);
const animMs = ref(0);
const dragging = ref(false);
const trackEl = ref(null);
let dragStartX = 0;
let dragStartProgress = 0;
let dragMoved = false;
// Incremented on every reset so a completion slide that was interrupted
// by a reset cannot later mark the strip done or emit `done`.
let slideSeq = 0;

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

async function runCompletionSlide() {
    state.value = 'sliding';
    const seq = ++slideSeq;
    animMs.value = reducedMotion() ? 60 : 1250;
    progress.value = 1;
    await wait(animMs.value);
    if (seq !== slideSeq) return; // a reset landed mid-slide
    state.value = 'done';
    emit('done');
}

function resetStrip(springMs) {
    slideSeq++;
    dragging.value = false;
    animMs.value = reducedMotion() ? 40 : springMs;
    progress.value = 0;
    state.value = 'idle';
}

function commitGesture() {
    if (state.value !== 'idle') return;
    if (props.recipient) {
        // The name is already here: pure presentation reveal, no emit.
        runCompletionSlide();
    } else {
        // Hold the cover where the gesture ended while the parent calls
        // the API; the watchers below resolve the hold.
        state.value = 'awaiting';
        animMs.value = 0;
        emit('commit');
    }
}

// Parent set the recipient while we were holding after a commit: slide open.
watch(
    () => props.recipient,
    (value) => {
        if (value && state.value === 'awaiting') {
            runCompletionSlide();
        }
    },
);

// Parent signalled failure (or wants a fresh strip): spring back / reset.
watch(
    () => props.failSignal,
    () => {
        // The failure spring matches the Reveal page's 300ms; a plain
        // reset uses the 280ms early-release spring.
        resetStrip(state.value === 'awaiting' ? 300 : 280);
    },
);

function onPointerDown(event) {
    if (state.value !== 'idle') return;
    dragging.value = true;
    dragMoved = false;
    dragStartX = event.clientX;
    dragStartProgress = progress.value;
    trackEl.value?.setPointerCapture?.(event.pointerId);
}

function onPointerMove(event) {
    if (!dragging.value || state.value !== 'idle') return;
    const rect = trackEl.value?.getBoundingClientRect();
    if (!rect || rect.width === 0) return;
    const dx = event.clientX - dragStartX;
    if (Math.abs(dx) > 6) dragMoved = true;
    progress.value = Math.min(1, Math.max(0, dragStartProgress + dx / rect.width));
}

function onPointerUp() {
    if (!dragging.value) return;
    dragging.value = false;
    if (state.value !== 'idle') return;
    // A tap (no real drag) commits too, like the old tear tap.
    if (!dragMoved || progress.value >= COMMIT_AT) {
        commitGesture();
    } else {
        // Released early: spring back, nothing is committed.
        animMs.value = reducedMotion() ? 40 : 280;
        progress.value = 0;
    }
}

function onPointerCancel() {
    if (!dragging.value) return;
    dragging.value = false;
    if (state.value === 'idle') {
        animMs.value = reducedMotion() ? 40 : 280;
        progress.value = 0;
    }
}

function onKeyCommit() {
    if (state.value === 'idle') {
        commitGesture();
    }
}
</script>

<template>
    <!-- Slide-to-reveal control: the name lives inside the track,
         under the sliding cover. -->
    <div
        ref="trackEl"
        class="tear-track"
        :class="{ done: state === 'done' }"
        role="button"
        tabindex="0"
        :aria-disabled="state !== 'idle'"
        aria-label="Slide to reveal your Secret Santa person"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerCancel"
        @keydown.enter.prevent="onKeyCommit"
        @keydown.space.prevent="onKeyCommit"
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
            <span class="tear-label">{{ state === 'done' ? 'Revealed' : 'Slide to reveal' }}</span>
            <span class="tear-knob">
                <ArrowRight :size="24" :stroke-width="2.6" />
            </span>
        </span>
    </div>
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
</style>
