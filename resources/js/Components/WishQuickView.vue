<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Check, ExternalLink, X } from 'lucide-vue-next';
import WishActionsMenu from './WishActionsMenu.vue';
import { pageScroller } from '../lib/scroller';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    // 'mine' shows the settings menu (edit/delete); other modes view only.
    mode: {
        type: String,
        default: 'member',
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'delete', 'edit']);

const closeButton = ref(null);
const broken = ref(false);
let savedScrollTop = null;
let previousOverflow = '';
let lockedScroller = null;

const displayName = computed(() => {
    const item = props.item;
    if (!item) return '';
    if (item.text) return item.text;
    if (item.kind === 'image') return 'Screenshot wish';
    if (item.kind === 'link' || item.link) return 'Link wish';
    return 'Untitled wish';
});

const showImage = computed(() => Boolean(props.item?.image) && !broken.value);

const stateLine = computed(() => {
    if (!props.item) return '';
    // Combined views flag the viewer's own items.
    if (props.item.mine) return 'This is your wish.';
    return '';
});

// Own items in a combined view get no menu at all. Single-owner pages
// never set the flag, so the 'mine' mode edit/delete flow is untouched.
const showMenu = computed(
    () => Boolean(props.item) && !props.item.mine && props.mode === 'mine',
);

// The guest shell is the scroller (the document never scrolls on
// guest pages), so locking means freezing the shell: remember its
// position and hide its overflow until the quick view closes.
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

watch(
    () => props.item,
    async (item) => {
        broken.value = false;
        if (item) {
            lockScroll();
            await nextTick();
            closeButton.value?.focus();
        } else {
            unlockScroll();
        }
    },
);

function onKeydown(event) {
    if (event.key === 'Escape' && props.item) {
        emit('close');
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if (props.item) lockScroll();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    unlockScroll();
});
</script>

<template>
    <div v-if="item" class="modal-backdrop" @click.self="emit('close')">
        <div class="qv-card pop-in" role="dialog" aria-modal="true" :aria-label="displayName">
            <button
                ref="closeButton"
                type="button"
                class="icon-btn qv-close"
                aria-label="Close"
                @click="emit('close')"
            >
                <X :size="19" />
            </button>

            <div v-if="showImage" class="qv-media">
                <img :src="item.image" :alt="displayName" @error="broken = true" />
            </div>

            <div class="qv-body">
                <h2 class="qv-name">{{ displayName }}</h2>
                <p v-if="item.notes" class="qv-notes">{{ item.notes }}</p>
                <p v-if="item.owner && !item.mine" class="qv-wisher">
                    Wished by {{ item.owner.display }}<span v-if="item.owner.nickname"> “{{ item.owner.nickname }}”</span>
                </p>

                <p v-if="stateLine" class="qv-state">
                    <Check :size="17" />
                    <span>{{ stateLine }}</span>
                </p>

                <div v-if="item.link || showMenu" class="qv-actions">
                    <a
                        v-if="item.link"
                        class="btn btn-secondary"
                        style="flex: 0 1 auto"
                        :href="item.link"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink :size="17" />
                        <span>Open link</span>
                    </a>
                    <!-- Parents gate the delete emit with a confirmation popup. -->
                    <WishActionsMenu
                        v-if="showMenu"
                        :item="item"
                        :busy="busy"
                        @edit="emit('edit', $event)"
                        @delete="emit('delete', $event)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Only combined views attach owner to items; unused everywhere else. */
.qv-wisher {
    margin: 8px 0 0;
    font-size: 0.9rem;
    color: var(--text-secondary);
}
</style>
