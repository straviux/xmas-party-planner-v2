<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, ExternalLink, X } from 'lucide-vue-next';
import { pageScroller } from '../lib/scroller';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    startIndex: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['close']);

const currentIndex = ref(0);
const dragOffset = ref(0);
const dragging = ref(false);
const entryDirection = ref('');
const brokenImageId = ref(null);
const closeButton = ref(null);

let dragPointerId = null;
let dragStartX = 0;
let savedScrollTop = null;
let previousOverflow = '';
let lockedScroller = null;

const currentItem = computed(() => props.items[currentIndex.value] || null);
const canPrevious = computed(() => currentIndex.value > 0);
const canNext = computed(() => currentIndex.value < props.items.length - 1);

const displayName = computed(() => {
    const item = currentItem.value;
    if (!item) return '';
    if (item.text) return item.text;
    if (item.kind === 'image') return 'Screenshot wish';
    if (item.kind === 'link' || item.link) return 'Link wish';
    return 'Untitled wish';
});

const ownerSecretName = computed(() => currentItem.value?.owner?.secret_name || null);
const ownerInitial = computed(() => ownerSecretName.value?.trim().charAt(0).toUpperCase() || '?');
const showImage = computed(
    () => Boolean(currentItem.value?.image) && brokenImageId.value !== currentItem.value?.id,
);

const slideClass = computed(() => ({
    dragging: dragging.value,
    'entry-next': entryDirection.value === 'next',
    'entry-prev': entryDirection.value === 'prev',
}));

const slideStyle = computed(() => ({
    transform: `translate3d(${dragOffset.value}px, 0, 0)`,
}));

function clampIndex(index) {
    if (!props.items.length) return 0;
    return Math.min(Math.max(index, 0), props.items.length - 1);
}

// The overlay is teleported to <body>, but the page underneath
// scrolls inside the guest shell, not the document: freezing the
// page means freezing the shell (position + overflow), so swipes on
// the viewer never move the grid behind it.
function lockScroll() {
    if (savedScrollTop === null) {
        lockedScroller = pageScroller();
        savedScrollTop = lockedScroller.scrollTop;
        previousOverflow = lockedScroller.style.overflow;
        lockedScroller.style.overflow = 'hidden';
    }
}

function unlockScroll() {
    if (savedScrollTop !== null) {
        const top = savedScrollTop;
        const target = lockedScroller;
        savedScrollTop = null;
        lockedScroller = null;
        if (target) {
            target.style.overflow = previousOverflow;
            target.scrollTop = top;
        }
    }
}

function goTo(index, direction) {
    const nextIndex = clampIndex(index);
    if (nextIndex === currentIndex.value) return;
    entryDirection.value = direction || (nextIndex > currentIndex.value ? 'next' : 'prev');
    dragOffset.value = 0;
    currentIndex.value = nextIndex;
}

function goPrevious() {
    if (canPrevious.value) goTo(currentIndex.value - 1, 'prev');
}

function goNext() {
    if (canNext.value) goTo(currentIndex.value + 1, 'next');
}

function isInteractiveTarget(target) {
    return Boolean(target?.closest?.('button, a, input, select, textarea, [data-no-drag]'));
}

function onPointerDown(event) {
    if (!currentItem.value || isInteractiveTarget(event.target)) return;
    if (event.button !== undefined && event.button !== 0) return;
    dragging.value = true;
    entryDirection.value = '';
    dragPointerId = event.pointerId;
    dragStartX = event.clientX;
    dragOffset.value = 0;
    event.currentTarget?.setPointerCapture?.(event.pointerId);
}

function onPointerMove(event) {
    if (!dragging.value || event.pointerId !== dragPointerId) return;
    dragOffset.value = event.clientX - dragStartX;
}

function finishPointerDrag(event, cancelled = false) {
    if (!dragging.value || event.pointerId !== dragPointerId) return;
    const offset = dragOffset.value;
    dragging.value = false;
    dragPointerId = null;

    if (!cancelled && offset <= -60 && canNext.value) {
        goNext();
        return;
    }
    if (!cancelled && offset >= 60 && canPrevious.value) {
        goPrevious();
        return;
    }
    dragOffset.value = 0;
}

function onPointerUp(event) {
    finishPointerDrag(event);
}

function onPointerCancel(event) {
    finishPointerDrag(event, true);
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        emit('close');
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        goPrevious();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        goNext();
    }
}

watch(
    () => props.startIndex,
    (index) => {
        currentIndex.value = clampIndex(index);
        dragOffset.value = 0;
        entryDirection.value = '';
    },
    { immediate: true },
);

watch(
    () => props.items.length,
    () => {
        currentIndex.value = clampIndex(currentIndex.value);
    },
);

onMounted(async () => {
    lockScroll();
    window.addEventListener('keydown', onKeydown);
    await nextTick();
    closeButton.value?.focus();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    unlockScroll();
});
</script>

<template>
    <!-- Teleported to <body>: the viewer is rendered inside a page card
         whose backdrop-filter would otherwise become the containing block
         for this fixed overlay, confining it to the card's box instead of
         the viewport (page visible at the edges, header scrolled away). -->
    <Teleport to="body">
    <div
        v-if="currentItem"
        class="reels-overlay"
        role="dialog"
        aria-modal="true"
        :aria-label="displayName"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerCancel"
    >
        <article
            :key="currentItem.id"
            class="reel-slide"
            :class="slideClass"
            :style="slideStyle"
        >
            <header class="reel-header">
                <div class="reel-owner">
                    <span class="reel-avatar" aria-hidden="true">{{ ownerInitial }}</span>
                    <span class="reel-owner-text">
                        <span v-if="ownerSecretName" class="reel-owner-name">{{ ownerSecretName }}</span>
                        <span
                            v-else
                            class="reel-owner-name reel-owner-placeholder"
                            role="img"
                            aria-label="Secret name not picked yet"
                        >???</span>
                    </span>
                    <span v-if="currentItem.mine" class="reel-mine-chip">Me</span>
                </div>

                <span class="reel-counter">{{ currentIndex + 1 }} / {{ items.length }}</span>

                <button
                    ref="closeButton"
                    type="button"
                    class="icon-btn reel-close"
                    aria-label="Close wishlist viewer"
                    @click="emit('close')"
                >
                    <X :size="20" />
                </button>
            </header>

            <main class="reel-main">
                <figure v-if="showImage" class="reel-media">
                    <img
                        :src="currentItem.image"
                        :alt="displayName"
                        @error="brokenImageId = currentItem.id"
                    />
                </figure>

                <h2 v-else class="reel-wish-text">{{ displayName }}</h2>

                <p v-if="showImage && currentItem.text" class="reel-caption">
                    {{ currentItem.text }}
                </p>

                <p v-if="currentItem.notes" class="reel-notes">{{ currentItem.notes }}</p>
            </main>

            <footer v-if="currentItem.link" class="reel-footer">
                <div class="reel-actions">
                    <a
                        class="btn btn-secondary reel-action"
                        :href="currentItem.link"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink :size="18" />
                        <span>Open link</span>
                    </a>
                </div>
            </footer>
        </article>

        <button
            v-show="canPrevious"
            type="button"
            class="reel-nav reel-nav-prev"
            aria-label="Previous wish"
            @click="goPrevious"
        >
            <ChevronLeft :size="26" />
        </button>
        <button
            v-show="canNext"
            type="button"
            class="reel-nav reel-nav-next"
            aria-label="Next wish"
            @click="goNext"
        >
            <ChevronRight :size="26" />
        </button>
    </div>
    </Teleport>
</template>

<style scoped>
.reels-overlay {
    position: fixed;
    inset: 0;
    z-index: 150;
    overflow-x: hidden;
    overflow-y: auto;
    /* Fully opaque: the page underneath must never bleed through, the
       viewer reads as its own full screen, edge to edge. */
    background: #0a0a19;
    color: var(--text-primary);
    touch-action: pan-y;
    overscroll-behavior: contain;
}

.reel-slide {
    position: relative;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    width: min(100%, 760px);
    /* Exactly one viewport tall: the teleported overlay is truly
       viewport-sized, so a min-height let the slide outgrow it and the
       overlay scrolled (header pushed out of view). The grid's flexible
       middle row absorbs the slack instead. */
    height: 100vh;
    height: 100dvh;
    margin: 0 auto;
    padding: max(18px, env(safe-area-inset-top)) 22px max(22px, env(safe-area-inset-bottom));
    transition: transform 0.22s ease;
    will-change: transform;
}

.reel-slide.dragging {
    transition: none;
}

.reel-slide.entry-next {
    animation: reel-in-from-right 0.3s ease both;
}

.reel-slide.entry-prev {
    animation: reel-in-from-left 0.3s ease both;
}

.reel-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 14px;
    min-height: 52px;
}

.reel-owner {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.reel-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5936b4, #c427fb);
    color: #ffffff;
    font-size: 1.05rem;
    font-weight: 800;
}

.reel-owner-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.25;
}

.reel-owner-name {
    overflow: hidden;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Same muted italic ??? as the reveal and recipient waiting states. */
.reel-owner-placeholder {
    font-style: italic;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
}

.reel-mine-chip {
    flex: none;
    padding: 6px 10px;
    border: 1px solid var(--card-border);
    border-radius: 999px;
    background: var(--glass);
    color: var(--text-secondary);
    font-size: 0.76rem;
    font-weight: 800;
}

.reel-counter {
    color: var(--text-secondary);
    font-size: 0.88rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.reel-close {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.1);
}

.reel-main {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 0;
    padding: 28px 38px;
    text-align: center;
}

.reel-media {
    /* The visual fills the slide's flexible middle row; the caption and
       notes take only the height they need below it. */
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    margin: 0;
}

.reel-media img {
    display: block;
    max-width: 100%;
    max-height: 100%;
    border-radius: 20px;
    object-fit: contain;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.42);
}

.reel-wish-text {
    max-width: 620px;
    margin: 0;
    font-size: clamp(2rem, 8vw, 4.4rem);
    font-weight: 850;
    line-height: 1.04;
    letter-spacing: -0.035em;
    overflow-wrap: anywhere;
}

.reel-caption {
    max-width: 580px;
    margin: 22px 0 0;
    font-size: clamp(1.25rem, 4vw, 2rem);
    font-weight: 750;
    line-height: 1.2;
    overflow-wrap: anywhere;
}

.reel-notes {
    max-width: 560px;
    margin: 18px 0 0;
    color: var(--text-secondary);
    font-size: 1rem;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.reel-footer {
    width: min(100%, 560px);
    margin: 0 auto;
}

.reel-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
}

.reel-action {
    flex: 1 1 210px;
    max-width: 300px;
}

.reel-nav {
    position: fixed;
    top: 50%;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    padding: 0;
    transform: translateY(-50%);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    cursor: pointer;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.reel-nav:hover {
    background: rgba(255, 255, 255, 0.18);
}

.reel-nav-prev {
    left: max(14px, env(safe-area-inset-left));
}

.reel-nav-next {
    right: max(14px, env(safe-area-inset-right));
}

@keyframes reel-in-from-right {
    from {
        opacity: 0;
        transform: translate3d(72px, 0, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
}

@keyframes reel-in-from-left {
    from {
        opacity: 0;
        transform: translate3d(-72px, 0, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
}

@media (max-width: 560px) {
    .reel-slide {
        padding-right: 16px;
        padding-left: 16px;
    }

    .reel-main {
        padding-right: 30px;
        padding-left: 30px;
    }

    .reel-nav {
        width: 42px;
        height: 42px;
    }

    .reel-nav-prev {
        left: 8px;
    }

    .reel-nav-next {
        right: 8px;
    }

    .reel-counter {
        display: none;
    }

    .reel-header {
        grid-template-columns: minmax(0, 1fr) auto;
    }
}

@media (prefers-reduced-motion: reduce) {
    .reel-slide,
    .reel-slide.entry-next,
    .reel-slide.entry-prev {
        animation: none !important;
        transition: none !important;
    }
}
</style>
