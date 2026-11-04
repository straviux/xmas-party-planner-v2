<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Bookmark, BookmarkCheck, Check, ExternalLink, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    // 'mine' shows Delete; 'member' shows Reserve actions.
    mode: {
        type: String,
        default: 'member',
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'reserve', 'delete']);

const closeButton = ref(null);
const broken = ref(false);
const armed = ref(false);
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
    if (props.item.reserved_by_me) return 'You reserved this wish. Nobody else can see that it was you.';
    if (props.item.reserved) return 'This wish is already reserved by someone.';
    return 'This wish is available.';
});

const action = computed(() => {
    const item = props.item;
    if (!item) return null;
    if (props.mode === 'mine') {
        return {
            label: armed.value ? 'Tap again to delete' : 'Delete this wish',
            className: armed.value ? 'btn btn-danger-ghost' : 'btn btn-secondary',
            icon: Trash2,
            disabled: props.busy,
        };
    }
    if (item.reserved_by_me) {
        return {
            label: 'Reserved by you, tap to undo',
            className: 'btn btn-secondary',
            icon: BookmarkCheck,
            disabled: props.busy,
        };
    }
    if (item.reserved) {
        return {
            label: 'Reserved',
            className: 'btn btn-secondary',
            icon: Bookmark,
            disabled: true,
        };
    }
    return {
        label: 'Reserve this wish',
        className: 'btn btn-primary',
        icon: Bookmark,
        disabled: props.busy,
    };
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
        armed.value = false;
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
    if (!props.item) return;
    if (props.mode === 'mine') {
        if (!armed.value) {
            armed.value = true;
            return;
        }
        armed.value = false;
        emit('delete', props.item);
        return;
    }
    emit('reserve', props.item);
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

                <p class="qv-state" :class="item.reserved ? 'reserved' : 'available'">
                    <Check v-if="!item.reserved" :size="17" />
                    <BookmarkCheck v-else :size="17" />
                    <span>{{ stateLine }}</span>
                </p>

                <div class="qv-actions">
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
