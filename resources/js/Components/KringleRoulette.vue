<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { RotateCw } from 'lucide-vue-next';

// Roulette reel for the Monito Monita weekly spin.
//
// The reel is pure theatre: it never knows any mapping. While idle it
// sits on one random name from `names`. A click emits `commit`
// (the parent makes the kringle_spin call) and the reel flickers
// through random names. When the parent sets `result`
// ({ name }, the recipient's real name from the spin response), the
// reel decelerates over about 26 ticks and lands exactly on
// result.name, pulses once, then emits `done` so the parent can swap
// in the spun view. If the call fails, the parent bumps `failSignal`
// and the reel stops back at idle with no `done`.
//
// Contract:
// - names (prop): the party's real names, display only. No entry is
//   ever treated as "the answer" until `result` arrives.
// - result (prop): undefined while no spin result is in hand; the
//   parent sets it to { name: string|null } once the spin response
//   arrives, and clears it back to undefined after `done`.
// - failSignal (prop): ANY change stops all timers and returns the
//   reel to idle, in any state.
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

// state: idle -> spinning (flicker, parent call in flight)
//             -> settling (result in hand, decelerating) -> done
const state = ref('idle');
const settled = ref(false);
// Incremented on every reset and spin start so a timer chain started
// by an earlier run can never land a name or emit `done` afterwards.
let runSeq = 0;
let timer = null;

function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function randomName(except) {
    const pool = props.names || [];
    if (!pool.length) return '???';
    if (pool.length === 1) return pool[0];
    let next = pool[Math.floor(Math.random() * pool.length)];
    // Never the same name twice in a row when the pool allows it.
    let guard = 0;
    while (next === except && guard++ < 10) {
        next = pool[Math.floor(Math.random() * pool.length)];
    }
    return next;
}

const shown = ref(randomName(null));

function stopTimers() {
    if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
    }
}

function flicker(seq) {
    if (seq !== runSeq) return;
    timer = window.setTimeout(() => {
        if (seq !== runSeq) return;
        shown.value = randomName(shown.value);
        flicker(seq);
    }, reducedMotion() ? 90 : 65);
}

function startSettle(seq) {
    stopTimers();
    state.value = 'settling';
    const reduced = reducedMotion();
    const ticks = reduced ? 5 : 26;
    const from = 90;
    const to = reduced ? 90 : 420;
    const target = props.result?.name ?? '???';
    let tick = 0;

    const step = () => {
        if (seq !== runSeq) return;
        tick++;
        if (tick >= ticks) {
            // The final tick lands exactly on the drawn name.
            shown.value = target;
            if (!reduced) settled.value = true;
            timer = window.setTimeout(() => {
                if (seq !== runSeq) return;
                state.value = 'done';
                emit('done');
            }, reduced ? 60 : 380);
            return;
        }
        shown.value = randomName(shown.value);
        const t = ticks > 1 ? (tick - 1) / (ticks - 1) : 1;
        const delay = Math.round(from + (to - from) * t);
        timer = window.setTimeout(step, delay);
    };

    timer = window.setTimeout(step, from);
}

function startSpin() {
    if (state.value !== 'idle') return;
    settled.value = false;
    state.value = 'spinning';
    const seq = ++runSeq;
    emit('commit');
    flicker(seq);
}

function resetToIdle() {
    runSeq++;
    stopTimers();
    state.value = 'idle';
    settled.value = false;
    shown.value = randomName(null);
}

// Parent set the result while the reel was flickering: decelerate
// onto it. A result arriving in any other state is not ours to run.
watch(
    () => props.result,
    (value) => {
        if (value !== undefined && value !== null && state.value === 'spinning') {
            startSettle(runSeq);
        }
    },
);

// Parent signalled failure: stop everything, back to idle, no done.
watch(
    () => props.failSignal,
    () => {
        resetToIdle();
    },
);

// The name list arriving (or changing) while idle: sit on a real
// name instead of the placeholder when we can.
watch(
    () => props.names,
    (list) => {
        if (state.value === 'idle' && !(list || []).includes(shown.value)) {
            shown.value = randomName(null);
        }
    },
);

onBeforeUnmount(() => {
    runSeq++;
    stopTimers();
});
</script>

<template>
    <!-- Roulette reel: display only. The drawn name reaches this
         component solely through the `result` prop, after the spin
         call has already decided it server-side. -->
    <div class="kringle-roulette" aria-hidden="true">
        <div class="reel-window">
            <span class="reel-marker reel-marker-left"></span>
            <span class="reel-name" :class="{ settled }">{{ shown }}</span>
            <span class="reel-marker reel-marker-right"></span>
        </div>

        <button
            type="button"
            class="btn btn-primary btn-block reel-button"
            :disabled="state !== 'idle'"
            @click="startSpin"
        >
            <RotateCw :size="18" />
            <span>{{ state === 'idle' ? 'Spin the roulette' : 'Spinning...' }}</span>
        </button>
    </div>
</template>

<style scoped>
.kringle-roulette {
    user-select: none;
    -webkit-user-select: none;
}

.reel-window {
    position: relative;
    height: 92px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--card-border);
    border-radius: 18px;
    background: var(--glass);
    overflow: hidden;
    padding: 0 34px;
}

.reel-name {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    line-height: 1.15;
    text-transform: uppercase;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.reel-name.settled {
    animation: reel-pulse 0.38s ease;
}

@keyframes reel-pulse {
    0% {
        transform: scale(1);
    }
    45% {
        transform: scale(1.09);
    }
    100% {
        transform: scale(1);
    }
}

/* Magenta triangles at the sides, pointing in at the centred name. */
.reel-marker {
    position: absolute;
    top: 50%;
    width: 0;
    height: 0;
    border-top: 7px solid transparent;
    border-bottom: 7px solid transparent;
    transform: translateY(-50%);
    pointer-events: none;
}

.reel-marker-left {
    left: 12px;
    border-left: 11px solid var(--accent, #c427fb);
}

.reel-marker-right {
    right: 12px;
    border-right: 11px solid var(--accent, #c427fb);
}

.reel-button {
    margin-top: 14px;
}
</style>
