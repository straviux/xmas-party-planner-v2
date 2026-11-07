<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { RotateCw } from 'lucide-vue-next';

// Roulette reveal for the Reveal page: the spin IS the draw. The guest
// presses the button, the reel flickers through secret names, and once
// the parent returns the drawn recipient the reel decelerates and
// lands exactly on it.
//
// Contract:
// - names (prop): the secret names the reel cycles through while
//   spinning. Real names never reach this component: secret names are
//   all a Secret Santa gets to see. May be empty, in which case the
//   reel just shows the ??? placeholder.
// - result (prop): undefined while the draw is unknown. Once the
//   parent sets it to { name } the reel decelerates over about 26
//   ticks (intervals growing from ~65ms to ~420ms) and the final tick
//   lands exactly on result.name, or on ??? when result.name is null
//   (the recipient has not picked a secret name yet). After a brief
//   settled pulse it emits `done`.
// - commit (emit): fired when the guest presses the spin button. The
//   parent calls the reveal API, which performs the draw server-side,
//   then sets `result`.
// - failSignal (prop): bumped by the parent after a failed reveal
//   call. Any change stops all timers and returns the reel to idle
//   with no `done` emitted, so the guest can spin again.
// - After `done` the reel is inert, still showing the landed name.
// - Purely decorative: the page owns all text and errors, so the
//   whole control is aria-hidden like the mascot wrap.
const props = defineProps({
    names: {
        type: Array,
        default: () => [],
    },
    result: {
        type: Object,
        default: undefined,
    },
    failSignal: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['commit', 'done']);

// state: idle -> spinning (flickering, parent call in flight)
//             -> settling (result known, decelerating)
//             -> settled (landed, pulse playing, then `done` emitted)
const state = ref('idle');
const shownName = ref(pickRandomName());
let flickerTimer = null;
let settleTimer = null;
// Incremented whenever the reel is reset or a new spin starts, so a
// timer chain from an interrupted run can never land or emit `done`.
let runSeq = 0;

function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function pickRandomName() {
    if (!props.names.length) return '???';
    return props.names[Math.floor(Math.random() * props.names.length)];
}

// A random name that is not the one currently shown, so the flicker
// never appears to stall on a repeat.
function pickNextName() {
    if (!props.names.length) return '???';
    if (props.names.length === 1) return props.names[0];
    let next = shownName.value;
    while (next === shownName.value) {
        next = props.names[Math.floor(Math.random() * props.names.length)];
    }
    return next;
}

function finalName() {
    return props.result && props.result.name ? props.result.name : '???';
}

function stopFlicker() {
    if (flickerTimer !== null) {
        window.clearInterval(flickerTimer);
        flickerTimer = null;
    }
}

function stopAllTimers() {
    stopFlicker();
    if (settleTimer !== null) {
        window.clearTimeout(settleTimer);
        settleTimer = null;
    }
}

function spin() {
    if (state.value !== 'idle') return;
    runSeq++;
    state.value = 'spinning';
    emit('commit');
    if (!props.names.length) {
        shownName.value = '???';
        return;
    }
    stopFlicker();
    flickerTimer = window.setInterval(() => {
        shownName.value = pickNextName();
    }, 65);
}

// The result arrived while flickering: swap to a decelerating tick
// chain whose last tick lands exactly on the result.
function beginSettle() {
    state.value = 'settling';
    stopFlicker();
    const seq = runSeq;
    const reduced = reducedMotion();
    const ticks = reduced ? 5 : 26;
    let tick = 0;

    const step = () => {
        if (seq !== runSeq) return;
        const isLast = tick === ticks - 1;
        const delay = reduced ? 90 : 65 + (420 - 65) * Math.pow(tick / (ticks - 1), 1.7);
        settleTimer = window.setTimeout(() => {
            if (seq !== runSeq) return;
            if (isLast) {
                shownName.value = finalName();
                finishSettle(seq, reduced);
            } else {
                shownName.value = pickNextName();
                tick++;
                step();
            }
        }, delay);
    };
    step();
}

// Landed: hold the settled emphasis briefly, then tell the parent.
function finishSettle(seq, reduced) {
    state.value = 'settled';
    settleTimer = window.setTimeout(
        () => {
            if (seq !== runSeq) return;
            emit('done');
        },
        reduced ? 120 : 560,
    );
}

// Parent set the result while we were flickering after a commit:
// start decelerating onto it.
watch(
    () => props.result,
    (value) => {
        if (value !== undefined && state.value === 'spinning') {
            beginSettle();
        }
    },
);

// Parent signalled failure: stop everything and go back to idle so
// the guest can spin again. No `done` is emitted.
watch(
    () => props.failSignal,
    () => {
        runSeq++;
        stopAllTimers();
        state.value = 'idle';
    },
);

// The name list can arrive after mount; while idle keep the still
// name in step with it.
watch(
    () => props.names,
    () => {
        if (state.value === 'idle') {
            shownName.value = pickRandomName();
        }
    },
);

onBeforeUnmount(() => {
    runSeq++;
    stopAllTimers();
});
</script>

<template>
    <!-- Roulette reveal control: a reel window the secret names
         flicker through, with the spin button under it. Decorative:
         the page handles all wording and errors. -->
    <div class="roulette" aria-hidden="true">
        <div class="roulette-window" :class="{ settled: state === 'settled' }">
            <span class="roulette-marker roulette-marker-left"></span>
            <span class="roulette-name">{{ shownName }}</span>
            <span class="roulette-marker roulette-marker-right"></span>
        </div>

        <button
            type="button"
            class="btn btn-primary btn-block roulette-spin"
            :disabled="state !== 'idle'"
            tabindex="-1"
            @click="spin"
        >
            <RotateCw :size="18" />
            <span v-if="state === 'idle'">Spin the roulette</span>
            <span v-else-if="state === 'settled'">Your recipient</span>
            <span v-else>Spinning...</span>
        </button>
    </div>
</template>

<style scoped>
.roulette {
    margin-top: 22px;
}

/* The reel window: one name at a time, centred, with a marker at
   each side pointing in at it. */
.roulette-window {
    position: relative;
    height: 92px;
    border-radius: 20px;
    background: var(--glass);
    border: 1px solid var(--card-border);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.roulette-window.settled {
    animation: roulette-pulse 0.55s ease-out;
}

.roulette-name {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    line-height: 1.15;
    color: var(--text-primary, #ffffff);
    white-space: nowrap;
    text-align: center;
    padding: 0 46px;
}

/* Small magenta triangles at the sides, pointing in at the name. */
.roulette-marker {
    position: absolute;
    top: 50%;
    margin-top: -9px;
    width: 0;
    height: 0;
    border-top: 9px solid transparent;
    border-bottom: 9px solid transparent;
}

.roulette-marker-left {
    left: 12px;
    border-left: 14px solid var(--accent, #c427fb);
}

.roulette-marker-right {
    right: 12px;
    border-right: 14px solid var(--accent, #c427fb);
}

.roulette-spin {
    margin-top: 16px;
}

@keyframes roulette-pulse {
    0% {
        transform: scale(1);
    }
    35% {
        transform: scale(1.03);
        border-color: var(--accent, #c427fb);
    }
    100% {
        transform: scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .roulette-window.settled {
        animation: none;
    }
}
</style>
