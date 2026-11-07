<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { RotateCw } from 'lucide-vue-next';

// Roulette reel for the Monito Monita weekly spin.
//
// The reel is pure theatre: it never knows any mapping. It renders a
// vertical strip of names scrolling behind a three-row window; the
// centre row is the reading position. While idle the strip sits
// still on one name. A click emits `commit` (the parent makes the
// kringle_spin call) and the strip scrolls fast through the party's
// names. When the parent sets `result` ({ name }, the recipient's
// real name from the spin response) the strip rebuilds as a landing
// run ending on that name and eases out onto it over about two
// seconds, pulses once, then emits `done` so the parent can swap in
// the spun view. If the call fails, the parent bumps `failSignal`
// and the reel stops back at idle with no `done`.
//
// Contract:
// - names (prop): the party's real names, display only. No entry is
//   ever treated as "the answer" until `result` arrives.
// - result (prop): undefined while no spin result is in hand; the
//   parent sets it to { name: string|null } once the spin response
//   arrives, and clears it back to undefined after `done`.
// - failSignal (prop): ANY change stops everything and returns the
//   reel to idle, in any state.
//
// The spin always animates, including on devices reporting reduced
// motion: the owner exempted the roulette (and the gift reveal) from
// the reduced-motion freeze, like the mascot (2026-11-07).
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

const ROW_H = 56;
const STEP_MS = 55;
const MIN_SPIN_MS = 950;
const LAND_MS = 2100;

// state: idle -> spinning (strip scrolling, parent call in flight)
//             -> landing (result in hand, easing onto it) -> done
const state = ref('idle');
const settled = ref(false);
// Incremented on every reset and spin start so a timer or interval
// started by an earlier run can never land a name or emit `done`.
let runSeq = 0;
let interval = null;
let timer = null;
let spinStartedAt = 0;

function pool() {
    return (props.names || []).filter((n) => typeof n === 'string' && n !== '');
}

function randomName(except) {
    const list = pool();
    if (!list.length) return '???';
    if (list.length === 1) return list[0];
    let next = list[Math.floor(Math.random() * list.length)];
    let guard = 0;
    while (next === except && guard++ < 10) {
        next = list[Math.floor(Math.random() * list.length)];
    }
    return next;
}

function shuffled(list) {
    const copy = [...list];
    for (let i = copy.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [copy[i], copy[j]] = [copy[j], copy[i]];
    }
    return copy;
}

// The strip: an array of names rendered top to bottom; centerIdx is
// the entry sitting in the window's centre row. transformFor centers
// it: the window shows three rows, so the strip shifts up by
// (centerIdx - 1) rows.
const strip = ref([]);
const centerIdx = ref(1);
const animate = ref(false);

const stripStyle = computed(() => ({
    transform: `translateY(${-(centerIdx.value - 1) * ROW_H}px)`,
    transition: animate.value ? '' : 'none',
}));

function setStrip(names, center, withTransition) {
    strip.value = names;
    centerIdx.value = center;
    animate.value = withTransition;
}

function idleStrip(centerName) {
    const list = pool();
    const center = centerName ?? randomName(null);
    let prev = randomName(center);
    let next = randomName(center);
    if (list.length === 2) {
        // With two names the neighbours are simply the other one.
        prev = list.find((n) => n !== center) ?? center;
        next = prev;
    }
    setStrip([prev, center, next], 1, false);
}

idleStrip(null);

function stopAll() {
    if (interval !== null) {
        window.clearInterval(interval);
        interval = null;
    }
    if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
    }
}

function startLoop(seq) {
    const list = pool();
    if (!list.length) return;
    const order = shuffled(list);
    // Enough cycles that the strip cannot run out during a slow
    // response (about eight seconds of scrolling), however small the
    // party is.
    const cycles = Math.max(12, Math.ceil(150 / order.length) + 2);
    const big = [];
    for (let i = 0; i < cycles; i++) big.push(...order);
    // Start on the name that was centred at rest: no visual jump.
    const current = strip.value[centerIdx.value];
    let start = big.indexOf(current);
    if (start < 0) start = 0;
    setStrip(big, start, true);

    interval = window.setInterval(() => {
        if (seq !== runSeq) return;
        if (centerIdx.value < strip.value.length - 2) {
            centerIdx.value++;
        }
    }, STEP_MS);
}

function startLanding(seq) {
    stopAll();
    state.value = 'landing';
    const target = props.result?.name ?? '???';
    const current = strip.value[centerIdx.value] ?? randomName(null);

    // The landing run: the centred name, a dozen-odd names with no
    // immediate repeats, then the drawn name last.
    const run = [current];
    let prev = current;
    for (let i = 0; i < 14; i++) {
        const next = randomName(prev);
        run.push(next);
        prev = next;
    }
    if (prev === target) {
        run.push(randomName(target));
    }
    run.push(target);

    // Snap to the top of the run without a transition, force the
    // browser to take it, then ease the whole run through the window.
    setStrip(run, 0, false);
    nextTick(() => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                if (seq !== runSeq) return;
                animate.value = true;
                centerIdx.value = run.length - 1;
                timer = window.setTimeout(() => {
                    if (seq !== runSeq) return;
                    settled.value = true;
                    timer = window.setTimeout(() => {
                        if (seq !== runSeq) return;
                        state.value = 'done';
                        emit('done');
                    }, 400);
                }, LAND_MS + 60);
            });
        });
    });
}

function maybeLand(seq) {
    if (seq !== runSeq || state.value !== 'spinning') return;
    if (props.result === undefined || props.result === null) return;
    const waited = Date.now() - spinStartedAt;
    if (waited < MIN_SPIN_MS) {
        timer = window.setTimeout(() => maybeLand(seq), MIN_SPIN_MS - waited);
        return;
    }
    startLanding(seq);
}

function startSpin() {
    if (state.value !== 'idle') return;
    settled.value = false;
    state.value = 'spinning';
    spinStartedAt = Date.now();
    const seq = ++runSeq;
    emit('commit');
    startLoop(seq);
    maybeLand(seq);
}

function resetToIdle() {
    runSeq++;
    stopAll();
    state.value = 'idle';
    settled.value = false;
    idleStrip(null);
}

watch(
    () => props.result,
    () => {
        maybeLand(runSeq);
    },
);

watch(
    () => props.failSignal,
    () => {
        resetToIdle();
    },
);

// The name list arriving (or changing) while idle: re-centre on a
// real name when the current centre is not in the list.
watch(
    () => props.names,
    () => {
        if (state.value === 'idle' && !pool().includes(strip.value[centerIdx.value])) {
            idleStrip(null);
        }
    },
);

onBeforeUnmount(() => {
    runSeq++;
    stopAll();
});
</script>

<template>
    <!-- Roulette reel: display only. The drawn name reaches this
         component solely through the `result` prop, after the spin
         call has already decided it server-side. -->
    <div class="kringle-roulette" aria-hidden="true">
        <div class="reel-window" :class="{ settled }">
            <div
                class="reel-strip"
                :class="{ scrolling: state === 'spinning' }"
                :style="stripStyle"
            >
                <div v-for="(name, i) in strip" :key="i" class="reel-row">
                    <span class="reel-name" :class="{ centre: i === centerIdx }">{{ name }}</span>
                </div>
            </div>
            <div class="reel-frame"></div>
            <span class="reel-marker reel-marker-left"></span>
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
    height: 168px;
    border: 1px solid var(--card-border);
    border-radius: 18px;
    background: var(--glass);
    overflow: hidden;
}

/* Fade the strip out at the top and bottom edges of the window. */
.reel-window::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(
        to bottom,
        rgba(28, 27, 51, 0.88),
        transparent 32%,
        transparent 68%,
        rgba(28, 27, 51, 0.88)
    );
}

.reel-strip {
    transition-property: transform;
    transition-duration: 2100ms;
    transition-timing-function: cubic-bezier(0.12, 0.75, 0.08, 1);
    will-change: transform;
}

.reel-strip.scrolling {
    transition-duration: 55ms;
    transition-timing-function: linear;
    filter: blur(0.7px);
}

.reel-row {
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 34px;
}

.reel-name {
    font-family: var(--font-display);
    font-size: 1.08rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.15;
    text-transform: uppercase;
    color: var(--text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    transition: color 0.15s ease, font-size 0.15s ease;
}

.reel-name.centre {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-primary);
}

/* The reading frame around the centre row. */
.reel-frame {
    position: absolute;
    left: 8px;
    right: 8px;
    top: 56px;
    height: 56px;
    border: 1px solid rgba(196, 39, 251, 0.35);
    border-radius: 12px;
    pointer-events: none;
}

.reel-window.settled .reel-frame {
    animation: frame-pulse 0.45s ease;
}

@keyframes frame-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(196, 39, 251, 0);
    }
    40% {
        box-shadow: 0 0 22px 2px rgba(196, 39, 251, 0.45);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(196, 39, 251, 0);
    }
}

/* Magenta triangles at the sides, pointing in at the centre row. */
.reel-marker {
    position: absolute;
    top: 50%;
    width: 0;
    height: 0;
    border-top: 7px solid transparent;
    border-bottom: 7px solid transparent;
    transform: translateY(-50%);
    pointer-events: none;
    z-index: 2;
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
