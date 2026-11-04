<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Check, ExternalLink, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    // 'mine' shows Delete; other modes are for viewing the wish.
    mode: {
        type: String,
        default: 'member',
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'delete']);

const closeButton = ref(null);
const broken = ref(false);
let savedScrollY = null;
let previousOverflow = '';

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

const action = computed(() => {
    const item = props.item;
    if (!item) return null;
    // Own items in a combined view get no action at all. Single-owner pages
    // never set the flag, so the 'mine' mode delete flow below is untouched.
    if (item.mine) return null;
    if (props.mode === 'mine') {
        return {
            label: 'Delete this wish',
            className: 'btn btn-secondary',
            icon: Trash2,
            disabled: props.busy,
        };
    }
    return null;
});

function lockScroll() {
    if (savedScrollY === null) {
        savedScrollY = window.scrollY;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
}

function unlockScroll() {
    if (savedScrollY !== null) {
        const y = savedScrollY;
        savedScrollY = null;
        document.body.style.overflow = previousOverflow;
        window.scrollTo(0, y);
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

function handleAction() {
    if (!props.item || props.mode !== 'mine') return;
    // Parents gate this with a confirmation popup before deleting.
    emit('delete', props.item);
}

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

                <div v-if="item.link || action" class="qv-actions">
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
                    <button
                        v-if="action"
                        type="button"
                        :class="action.className"
                        :disabled="action.disabled"
                        @click="handleAction"
                    >
                        <component :is="action.icon" :size="17" />
                        <span>{{ action.label }}</span>
                    </button>
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
