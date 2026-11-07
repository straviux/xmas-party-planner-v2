<script setup>
import { computed } from 'vue';

// Gift reveal for the Monito Monita result: a wrapped gift that
// wiggles, pops its lid, bursts confetti, and the drawn name rises
// into view. Played once, when the component mounts right after a
// fresh spin; a guest who returns later sees the plain result card
// instead (the page decides which to render).
//
// Pure CSS shapes and theme colours, no images. The base styles are
// the SETTLED state (lid off, name visible), and every animation
// lives under prefers-reduced-motion: no-preference with backwards
// fill, so reduced-motion guests simply get the settled state.
const props = defineProps({
    name: {
        type: String,
        default: '',
    },
});

const COLORS = ['#c427fb', '#e8b23a', '#7a4fd0', '#f7e8c9', '#01b7c5'];

const pieces = computed(() =>
    Array.from({ length: 16 }, (_, i) => {
        const angle = (i / 16) * Math.PI * 2 + Math.random() * 0.6;
        const dist = 72 + Math.random() * 68;
        return {
            '--dx': `${Math.round(Math.cos(angle) * dist)}px`,
            '--dy': `${Math.round(Math.sin(angle) * dist * 0.55 + 70)}px`,
            '--rot': `${Math.round(Math.random() * 420 - 210)}deg`,
            background: COLORS[i % COLORS.length],
            animationDelay: `${(0.52 + Math.random() * 0.1).toFixed(2)}s`,
            width: `${6 + Math.round(Math.random() * 4)}px`,
            height: `${9 + Math.round(Math.random() * 5)}px`,
        };
    }),
);
</script>

<template>
    <div class="gift-reveal">
        <p class="buying-for-label">You are buying for</p>

        <div class="gift-stage">
            <div class="gift-glow"></div>
            <div class="gift">
                <div class="gift-lid">
                    <div class="bow">
                        <span class="bow-loop bow-left"></span>
                        <span class="bow-loop bow-right"></span>
                        <span class="bow-knot"></span>
                    </div>
                    <span class="lid-ribbon"></span>
                </div>
                <div class="gift-body">
                    <span class="body-ribbon"></span>
                </div>
                <div class="burst"></div>
                <span
                    v-for="(piece, i) in pieces"
                    :key="i"
                    class="confetti"
                    :style="piece"
                ></span>
            </div>
        </div>

        <p class="gift-name">{{ name }}</p>
    </div>
</template>

<style scoped>
.gift-reveal {
    text-align: center;
    margin-bottom: 16px;
}

.buying-for-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
    margin: 6px 0 0;
}

.gift-stage {
    position: relative;
    display: flex;
    justify-content: center;
    padding-top: 64px;
}

.gift-glow {
    position: absolute;
    top: 58px;
    left: 50%;
    width: 190px;
    height: 150px;
    transform: translateX(-50%);
    background: radial-gradient(
        closest-side,
        rgba(196, 39, 251, 0.32),
        rgba(89, 54, 180, 0.14) 55%,
        transparent 72%
    );
    pointer-events: none;
}

.gift {
    position: relative;
    width: 118px;
}

.gift-body {
    position: relative;
    height: 86px;
    border-radius: 10px;
    background: linear-gradient(165deg, #5936b4, #362a84);
    border: 1px solid rgba(255, 255, 255, 0.14);
    overflow: hidden;
}

.body-ribbon {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 50%;
    width: 16px;
    transform: translateX(-50%);
    background: linear-gradient(180deg, #e8b23a, #b8860b);
}

/* Settled state: the lid rests popped off, up and tilted. The pop
   animation below starts it from closed under no-preference. */
.gift-lid {
    position: absolute;
    z-index: 3;
    left: -5px;
    top: -30px;
    width: 128px;
    height: 30px;
    border-radius: 8px;
    background: linear-gradient(160deg, #6d45c9, #5936b4);
    border: 1px solid rgba(255, 255, 255, 0.16);
    transform: translate(-12px, -50px) rotate(-16deg);
}

.lid-ribbon {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 50%;
    width: 16px;
    transform: translateX(-50%);
    background: linear-gradient(180deg, #e8b23a, #b8860b);
}

.bow {
    position: absolute;
    left: 50%;
    top: -19px;
    transform: translateX(-50%);
    width: 64px;
    height: 22px;
}

.bow-loop {
    position: absolute;
    top: 0;
    width: 30px;
    height: 20px;
    border: 6px solid #e8b23a;
    border-radius: 50%;
}

.bow-left {
    left: 0;
    transform: rotate(-26deg);
}

.bow-right {
    right: 0;
    transform: rotate(26deg);
}

.bow-knot {
    position: absolute;
    left: 50%;
    top: 5px;
    width: 13px;
    height: 13px;
    transform: translateX(-50%);
    border-radius: 50%;
    background: #b8860b;
}

.burst {
    position: absolute;
    z-index: 2;
    left: 50%;
    top: -8px;
    width: 90px;
    height: 40px;
    transform: translateX(-50%) scale(0.4);
    opacity: 0;
    background: radial-gradient(
        closest-side,
        rgba(247, 232, 201, 0.85),
        rgba(232, 178, 58, 0.35) 55%,
        transparent 75%
    );
    pointer-events: none;
}

.confetti {
    position: absolute;
    z-index: 4;
    left: 50%;
    top: -4px;
    border-radius: 2px;
    opacity: 0;
    pointer-events: none;
}

.gift-name {
    font-family: var(--font-display);
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1.15;
    color: var(--text-primary);
    margin: 14px 0 0;
}

@media (prefers-reduced-motion: no-preference) {
    .gift {
        animation: gift-wiggle 0.5s ease-in-out 0.05s;
    }

    .gift-lid {
        animation: lid-pop 0.6s cubic-bezier(0.2, 1.4, 0.36, 1) 0.52s backwards;
    }

    .gift-glow {
        animation: glow-in 0.8s ease-out 0.5s backwards;
    }

    .burst {
        animation: burst-flash 0.7s ease-out 0.52s both;
    }

    .confetti {
        animation: confetti-fly 0.95s ease-out both;
    }

    .gift-name {
        animation: name-rise 0.55s ease-out 0.7s backwards;
    }
}

@keyframes gift-wiggle {
    0%,
    100% {
        transform: rotate(0deg);
    }
    25% {
        transform: rotate(-2.6deg);
    }
    55% {
        transform: rotate(2.4deg);
    }
    80% {
        transform: rotate(-1.2deg);
    }
}

@keyframes lid-pop {
    from {
        transform: translate(0, 0) rotate(0deg);
    }
    to {
        transform: translate(-12px, -50px) rotate(-16deg);
    }
}

@keyframes glow-in {
    from {
        opacity: 0;
        transform: translateX(-50%) scale(0.6);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) scale(1);
    }
}

@keyframes burst-flash {
    0% {
        opacity: 0;
        transform: translateX(-50%) scale(0.4);
    }
    25% {
        opacity: 0.9;
    }
    100% {
        opacity: 0;
        transform: translateX(-50%) scale(1.6);
    }
}

@keyframes confetti-fly {
    0% {
        opacity: 1;
        transform: translate(-50%, -50%) rotate(0deg);
    }
    30% {
        opacity: 1;
        transform: translate(
                calc(-50% + var(--dx) * 0.7),
                calc(-50% - 62px)
            )
            rotate(calc(var(--rot) * 0.6));
    }
    100% {
        opacity: 0;
        transform: translate(
                calc(-50% + var(--dx)),
                calc(-50% + var(--dy))
            )
            rotate(var(--rot));
    }
}

@keyframes name-rise {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.94);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
