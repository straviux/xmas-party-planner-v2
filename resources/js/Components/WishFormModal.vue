<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, CircleAlert, ImagePlus, Plus, X } from 'lucide-vue-next';
import { post } from '../lib/api';

const props = defineProps({
    // The wish being edited, or null for the add form. Parents mount this
    // component fresh for each open (v-if), so the fields initialise from
    // the item once, here at setup.
    item: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'saved']);

const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

const isEdit = computed(() => Boolean(props.item));

const text = ref(props.item?.text || '');
const link = ref(props.item?.link || '');
const notes = ref(props.item?.notes || '');

// Photo handling. 'unchanged' keeps the item's current photo (or, in add
// mode, no photo yet); 'replaced' holds a fresh data URL in imageData;
// 'removed' drops the item's photo on save. The image key is only sent to
// wishlist_update when the state is 'replaced' (data URL) or 'removed'
// (null); an untouched photo is omitted entirely.
const photoState = ref('unchanged');
const imageData = ref('');
const imageError = ref('');
const fileInput = ref(null);
const wishTextInput = ref(null);

const submitting = ref(false);
const formError = ref('');

const FORM_ERRORS = {
    wish_empty: 'Add a short description, a link, or a screenshot, at least one.',
    image_too_large: 'That image is too large to save. Try a smaller screenshot.',
    image_invalid: 'That image could not be saved. Try a different screenshot.',
    not_claimed: 'You need to enter the party first before adding wishes.',
    wish_not_found: 'That wish is already gone.',
    forbidden: 'You can only edit your own wishes.',
};

const previewSrc = computed(() => {
    if (photoState.value === 'replaced') return imageData.value;
    if (photoState.value === 'unchanged') return props.item?.image || '';
    return '';
});

const hasPhoto = computed(() => {
    if (photoState.value === 'replaced') return Boolean(imageData.value);
    if (photoState.value === 'removed') return false;
    return Boolean(props.item?.image);
});

function requestClose() {
    if (submitting.value) return;
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') requestClose();
}

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);
    await nextTick();
    wishTextInput.value?.focus();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});

function openFilePicker() {
    fileInput.value?.click();
}

function onFileChange(event) {
    imageError.value = '';
    const file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    if (!ACCEPTED_TYPES.includes(file.type)) {
        imageError.value =
            'That file type is not allowed. Use a PNG, JPEG, GIF, or WebP image.';
        return;
    }
    if (file.size > MAX_IMAGE_BYTES) {
        imageError.value = 'That image is too big. The limit is 10 MB.';
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        imageData.value = typeof reader.result === 'string' ? reader.result : '';
        if (imageData.value) photoState.value = 'replaced';
    };
    reader.onerror = () => {
        imageError.value = 'That image could not be read. Try a different one.';
    };
    reader.readAsDataURL(file);
}

function removePhoto() {
    imageData.value = '';
    imageError.value = '';
    photoState.value = isEdit.value && props.item?.image ? 'removed' : 'unchanged';
}

function undoPhotoChange() {
    imageData.value = '';
    imageError.value = '';
    photoState.value = 'unchanged';
}

async function submit() {
    formError.value = '';
    const payload = {
        text: text.value.trim() || null,
        link: link.value.trim() || null,
        notes: notes.value.trim() || null,
    };
    if (!payload.text && !payload.link && !hasPhoto.value) {
        formError.value = FORM_ERRORS.wish_empty;
        return;
    }
    submitting.value = true;
    try {
        if (isEdit.value) {
            const body = { item_id: props.item.id, ...payload };
            if (photoState.value === 'replaced') body.image = imageData.value;
            else if (photoState.value === 'removed') body.image = null;
            const res = await post('wishlist_update', body);
            emit('saved', res.item ?? null, 'edit');
        } else {
            const res = await post('wishlist_add', {
                ...payload,
                image: imageData.value || null,
            });
            emit('saved', res.item ?? null, 'add');
        }
    } catch (err) {
        formError.value =
            FORM_ERRORS[err.code] ||
            err.message ||
            (isEdit.value
                ? 'Your changes could not be saved. Please try again.'
                : 'That wish could not be added. Please try again.');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="requestClose">
        <div
            class="modal-card pop-in"
            style="width: min(94vw, 560px)"
            role="dialog"
            aria-modal="true"
            :aria-label="isEdit ? 'Edit wish' : 'Add a wish'"
        >
            <h3 class="modal-title">{{ isEdit ? 'Edit wish' : 'Add a wish' }}</h3>
            <form style="margin-top: 14px" @submit.prevent="submit">
                <p v-if="formError" class="error-text">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ formError }}</span>
                </p>

                <label class="field">
                    <span class="field-label">Wish</span>
                    <input
                        ref="wishTextInput"
                        v-model="text"
                        class="input"
                        type="text"
                        autocomplete="off"
                        placeholder="For example: A warm knitted scarf"
                    />
                </label>

                <label class="field">
                    <span class="field-label">Link (optional)</span>
                    <input
                        v-model="link"
                        class="input"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        placeholder="Paste a shop link, if you have one"
                    />
                </label>

                <label class="field">
                    <span class="field-label">Notes (optional)</span>
                    <textarea
                        v-model="notes"
                        class="textarea"
                        placeholder="Where to buy, size, colour"
                    ></textarea>
                </label>

                <div class="field">
                    <span class="field-label">Screenshot (optional)</span>
                    <div v-if="previewSrc" class="wish-preview">
                        <img :src="previewSrc" alt="Your screenshot preview" />
                        <button
                            type="button"
                            class="icon-btn wish-preview-remove"
                            :aria-label="
                                photoState === 'replaced' && item?.image
                                    ? 'Undo the new photo and keep the current one'
                                    : 'Remove screenshot'
                            "
                            @click="photoState === 'replaced' && item?.image ? undoPhotoChange() : removePhoto()"
                        >
                            <X :size="17" />
                        </button>
                    </div>
                    <p v-if="photoState === 'removed'" class="small muted" style="margin: 0 0 10px">
                        The photo will be removed when you save.
                        <button type="button" class="wish-photo-undo" @click="undoPhotoChange">
                            Undo
                        </button>
                    </p>
                    <div class="wish-photo-actions">
                        <button type="button" class="btn btn-secondary" @click="openFilePicker">
                            <ImagePlus :size="18" />
                            <span>{{ previewSrc ? 'Replace photo' : 'Add a screenshot' }}</span>
                        </button>
                        <button
                            v-if="previewSrc && photoState === 'unchanged' && item?.image"
                            type="button"
                            class="btn btn-secondary"
                            @click="removePhoto"
                        >
                            <X :size="18" />
                            <span>Remove photo</span>
                        </button>
                    </div>
                    <p class="small muted" style="margin: 8px 0 0">
                        PNG, JPEG, GIF, or WebP, up to 10 MB.
                    </p>
                    <input
                        ref="fileInput"
                        class="file-input-hidden"
                        type="file"
                        accept="image/png,image/jpeg,image/gif,image/webp"
                        @change="onFileChange"
                    />
                    <p v-if="imageError" class="error-text" style="margin-top: 12px">
                        <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                        <span>{{ imageError }}</span>
                    </p>
                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        :disabled="submitting"
                        @click="requestClose"
                    >
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" :disabled="submitting">
                        <template v-if="isEdit">
                            <Check :size="18" />
                            <span>{{ submitting ? 'Saving...' : 'Save changes' }}</span>
                        </template>
                        <template v-else>
                            <Plus :size="18" />
                            <span>{{ submitting ? 'Adding...' : 'Add to my wishes' }}</span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<style scoped>
.wish-photo-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.wish-photo-undo {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: 700;
    color: var(--lavender);
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
}
</style>
