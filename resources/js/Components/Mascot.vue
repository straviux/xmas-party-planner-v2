<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
let revertTimer = null;

// During a reaction the pool line wins; with no reaction and no prop
// message there is simply no bubble. With no prop message a tap still
// shows the pool line for the reaction window, then hides again.
const displayedMessage = computed(() => reactionLine.value ?? props.message);

// The bubble's text types itself out, chat style, whenever the target
// line changes: first mount, a reaction line, or the revert back to
// the prop message. About 24ms per character, sped up on long lines
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

    window.clearTimeout(revertTimer);
    revertTimer = window.setTimeout(() => {
        reactionLine.value = null;
        scorRef.value?.classList.remove('reacting');
    }, 2800);
}

onBeforeUnmount(() => {
    window.clearTimeout(revertTimer);
    window.clearInterval(typeTimer);
});
</script>

<template>
    <div class="mascot-wrap" aria-hidden="true" @click="react">
        <div v-if="displayedMessage" :key="displayedMessage" class="mascot-bubble">{{ typedText }}</div>
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
