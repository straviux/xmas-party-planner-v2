<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import scorHatUrl from '../../assets/scorHatData.js';

const props = defineProps({
    message: {
        type: String,
        default: '',
    },
});

// Tapping Scor swaps the bubble to a random festive line for a few
// seconds and plays a little hop (the .reacting class in app.css).
// Purely decorative: the wrap is aria-hidden and the prop message
// always comes back once the reaction window ends.
const REACTION_LINES = [
    'Ho ho ho!',
    'Have you been nice this year?',
    'No peeking at the gifts!',
    'Hot cocoa fixes everything.',
    'Your Secret Santa is watching.',
    'Make a wish, check it twice.',
    'The elves are on a deadline.',
    'Santa hats suit everyone.',
];

const scorRef = ref(null);
const reactionLine = ref(null);
const lastLineIndex = ref(-1);

// Once a tap picks a pool line it STAYS as the bubble text until the
// next tap picks another (owner's call, 2026-11-04); it never reverts
// to the prop message on its own. Before the first tap the prop
// message shows; with no prop message and no tap there is no bubble.
const displayedMessage = computed(() => reactionLine.value ?? props.message);

// The bubble's text types itself out, chat style, whenever the target
// line changes: first mount or a new reaction line picked by a tap.
// About 24ms per character, sped up on long lines
// so even the longest finishes within roughly 700ms. Purely visual:
// the whole mascot wrap is aria-hidden, so assistive tech never sees
// the half-typed text at all.
const typedText = ref('');
let typeTimer = null;

function startTyping(text) {
    window.clearInterval(typeTimer);
    typeTimer = null;
    typedText.value = '';

    if (!text) {
        return;
    }

    // Array.from keeps multi-byte characters whole.
    const chars = Array.from(text);
    const interval = Math.max(1, Math.min(24, Math.floor(700 / chars.length)));
    let count = 0;
    typeTimer = window.setInterval(() => {
        count += 1;
        typedText.value = chars.slice(0, count).join('');
        if (count >= chars.length) {
            window.clearInterval(typeTimer);
            typeTimer = null;
        }
    }, interval);
}

watch(displayedMessage, (text) => startTyping(text), { immediate: true });

// Snowfall behind Scor: the owner's pure-CSS snow, rebuilt at
// runtime because this project has no SCSS for its @for loops.
// Each flake gets a random start position, size, opacity, fall
// speed, and sideways drift; the negative delay is spread across
// the whole fall duration so a shower opens with flakes already
// spread through the layer instead of dropping as a curtain.
// All flakes share one keyframes rule (.scor-snow-fall in
// app.css) driven by the --drift custom property. The snow is
// part of the mascot presentation, so it shares the mascot's
// reduced-motion exemption (owner's call, see app.css).
//
// The snow is occasional, never constant (owner's call): the
// layer is not even rendered while idle, so it costs nothing
// between showers. It shows for tap bursts (see react()) and
// for self-scheduled random showers: wait a random 14 to 30s
// gap, snow for a random 5 to 8s, then wait again. One shared
// stop timestamp governs every trigger, so overlapping bursts
// simply keep the snow on until the later end time; a single
// stop timer is re-armed on every start, never two competing
// off-timers. The fade in and out lives in app.css (.snow-fade).
const SNOWFLAKE_COUNT = 90;

function snowRandom(min, max) {
    return min + Math.random() * (max - min);
}

const snowflakes = Array.from({ length: SNOWFLAKE_COUNT }, () => {
    const duration = snowRandom(7, 16);
    return {
        left: snowRandom(0, 100),
        size: snowRandom(2, 5),
        opacity: snowRandom(0.3, 1),
        duration,
        delay: -snowRandom(0, duration),
        drift: snowRandom(-34, 34),
    };
});

const snowing = ref(false);
let snowStopAt = 0;
let snowStopTimer = null;
let snowCycleTimer = null;

function startSnow(durationMs) {
    snowStopAt = Math.max(snowStopAt, Date.now() + durationMs);
    snowing.value = true;

    window.clearTimeout(snowStopTimer);
    snowStopTimer = window.setTimeout(() => {
        snowStopTimer = null;
        snowStopAt = 0;
        snowing.value = false;
    }, snowStopAt - Date.now());
}

// Self-rescheduling timeout chain (not an interval) so every
// gap and every shower duration is freshly randomized. The
// next gap is scheduled from the end of the current shower.
function scheduleSnowCycle(delayMs) {
    window.clearTimeout(snowCycleTimer);
    snowCycleTimer = window.setTimeout(() => {
        snowCycleTimer = null;
        const duration = snowRandom(5000, 8000);
        startSnow(duration);
        scheduleSnowCycle(duration + snowRandom(14000, 30000));
    }, delayMs);
}

onMounted(() => {
    scheduleSnowCycle(snowRandom(14000, 30000));
});

function pickLine() {
    let index = Math.floor(Math.random() * REACTION_LINES.length);
    if (index === lastLineIndex.value) {
        index = (index + 1) % REACTION_LINES.length;
    }
    lastLineIndex.value = index;
    return REACTION_LINES[index];
}

function react() {
    reactionLine.value = pickLine();

    // Restart the one-shot hop even mid-reaction: drop the class,
    // force a reflow so the browser forgets the running animation,
    // then re-apply it.
    const el = scorRef.value;
    if (el) {
        el.classList.remove('reacting');
        void el.offsetWidth;
        el.classList.add('reacting');
    }

    // A tap also bursts the snow for a few seconds; it merges
    // with any random shower in progress via the shared stop
    // timestamp in startSnow().
    startSnow(3200);
}

onBeforeUnmount(() => {
    window.clearInterval(typeTimer);
    window.clearTimeout(snowStopTimer);
    window.clearTimeout(snowCycleTimer);
});
</script>

<template>
    <div class="mascot-wrap" aria-hidden="true" @click="react">
        <Transition name="snow-fade">
            <div v-if="snowing" class="scor-snow" aria-hidden="true">
                <span
                    v-for="(flake, index) in snowflakes"
                    :key="index"
                    class="scor-snow-flake"
                    :style="{
                        left: `${flake.left}%`,
                        width: `${flake.size}px`,
                        height: `${flake.size}px`,
                        opacity: flake.opacity,
                        animationDuration: `${flake.duration}s`,
                        animationDelay: `${flake.delay}s`,
                        '--drift': `${flake.drift}px`,
                    }"
                ></span>
            </div>
        </Transition>
        <!-- No :key on the bubble, deliberately: keying it by the
             message remounted the element on every tap, restarting
             its pop-in and float animations and making it jump.
             Reusing the element keeps both animations continuous;
             only the text changes, and the typewriter watcher above
             re-types the new line into the same bubble.
             Inside, an invisible sizer holds the FULL line's layout
             (see .mascot-bubble-sizer in app.css) while the typed
             text overlays it, so the bubble's box never resizes
             while typing: without it the box collapsed to an empty
             pill on every tap and grew back character by character,
             shoving Scor below it up and down. -->
        <div v-if="displayedMessage" class="mascot-bubble">
            <span class="mascot-bubble-sizer">{{ displayedMessage }}</span>
            <span class="mascot-bubble-typed">{{ typedText }}</span>
        </div>
        <div ref="scorRef" class="scor">
            <div class="scor-head">
                <img class="scor-hat-img" :src="scorHatUrl" alt="" />
                <div class="scor-face">
                    <div class="scor-eye scor-eye-left"></div>
                    <div class="scor-eye scor-eye-right"></div>
                    <div class="scor-face-lower">
                        <div class="scor-nose"></div>
                        <div class="scor-mouth">
                            <div class="scor-mouth-outer"></div>
                            <div class="scor-mouth-inner"></div>
                        </div>
                        <div class="scor-mouth-line"></div>
                    </div>
                    <div class="scor-blush scor-blush-left"></div>
                    <div class="scor-blush scor-blush-right"></div>
                </div>
                <div class="scor-face-fluff scor-face-fluff-left"></div>
                <div class="scor-face-fluff scor-face-fluff-right"></div>
                <div class="scor-ear scor-ear-left"></div>
                <div class="scor-ear-right-wrap">
                    <div class="scor-ear scor-ear-right"></div>
                    <div class="scor-ear-right-fluff"></div>
                </div>
                <div class="scor-beard"></div>
            </div>
            <div class="scor-body">
                <div class="scor-tail"></div>
                <div class="scor-torso"></div>
                <div class="scor-neck"></div>
                <div class="scor-arm scor-arm-left"></div>
                <div class="scor-arm scor-arm-right"></div>
                <div class="scor-leg scor-leg-left">
                    <div class="scor-leg-foot"><div class="scor-leg-foot-pad"></div></div>
                </div>
                <div class="scor-leg scor-leg-right">
                    <div class="scor-leg-foot"><div class="scor-leg-foot-pad"></div></div>
                </div>
            </div>
        </div>
    </div>
</template>
