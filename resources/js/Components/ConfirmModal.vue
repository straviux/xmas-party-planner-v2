<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The one shared confirm dialog for the host admin. Custom modal, never a
 * browser confirm(). The body text is supplied by the caller and must state
 * exactly what the action will and will not do.
 *
 * The cancel button takes focus on open: for destructive actions the safe
 * choice is the default.
 */
const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    body: {
        type: String,
        required: true,
    },
    confirmLabel: {
        type: String,
        default: 'Yes, continue',
    },
    cancelLabel: {
        type: String,
        default: 'Cancel',
    },
    danger: {
        type: Boolean,
        default: false,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel']);

const cancelButton = ref(null);

function onKeydown(event) {
    if (event.key === 'Escape' && !props.busy) {
        emit('cancel');
    }
}

function backdropClick() {
    if (!props.busy) {
        emit('cancel');
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    nextTick(() => {
        if (cancelButton.value) cancelButton.value.focus();
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div class="modal-backdrop" @click.self="backdropClick">
        <div
            class="modal-card pop-in"
            :class="{ 'modal-danger': danger }"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
        >
            <h3 class="modal-title">{{ title }}</h3>
            <p class="muted" style="margin: 0; line-height: 1.55">{{ body }}</p>
            <div class="modal-actions">
                <button
                    ref="cancelButton"
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="busy"
                    @click="emit('cancel')"
                >
                    {{ cancelLabel }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm"
                    :class="danger ? 'btn-danger' : 'btn-teal'"
                    :disabled="busy"
                    @click="emit('confirm')"
                >
                    {{ busy ? 'Working...' : confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
