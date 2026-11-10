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

// Photos: up to three per wish. Slots are either 'existing' (a photo
// the wish already has, referenced by its server index, previewed by
// its URL) or 'new' (a fresh data URL from the picker). On save the
// images key is sent only when the set changed (edits), as the full
// desired set: "keep:N" for existing slots, the data URL for new
// ones, and dropped slots simply absent. Adds send their data URLs.
const MAX_PHOTOS = 3;
const photos = ref(
    (props.item?.images || []).map((src, index) => ({ kind: 'existing', index, src })),
);
const imageError = ref('');
const fileInput = ref(null);
const wishTextInput = ref(null);
const replaceTarget = ref(null);

const initialPhotoKey = (props.item?.images || []).map((_, index) => `e${index}`).join(',');
const photosChanged = computed(
    () =>
        photos.value
            .map((slot) => (slot.kind === 'existing' ? `e${slot.index}` : 'new'))
            .join(',') !== initialPhotoKey,
);
const hasPhoto = computed(() => photos.value.length > 0);

const submitting = ref(false);
const formError = ref('');

const FORM_ERRORS = {
    wish_empty: 'Add a short description, a link, or a screenshot, at least one.',
    too_many_images: 'A wish can have up to three photos.',
    image_too_large: 'That image is too large to save. Try a smaller screenshot.',
    image_invalid: 'That image could not be saved. Try a different screenshot.',
    not_claimed: 'You need to enter the party first before adding wishes.',
    wish_not_found: 'That wish is already gone.',
    forbidden: 'You can only edit your own wishes.',
};

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

function openFilePicker(target = null) {
    if (target === null && photos.value.length >= MAX_PHOTOS) {
        imageError.value = 'A wish can have up to three photos. Remove one first.';
        return;
    }
    replaceTarget.value = target;
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
        const dataUrl = typeof reader.result === 'string' ? reader.result : '';
        if (!dataUrl) return;
        const slot = { kind: 'new', src: dataUrl, dataUrl };
        if (replaceTarget.value !== null && photos.value[replaceTarget.value]) {
            photos.value.splice(replaceTarget.value, 1, slot);
        } else if (photos.value.length < MAX_PHOTOS) {
            photos.value.push(slot);
        }
        replaceTarget.value = null;
    };
    reader.onerror = () => {
        imageError.value = 'That image could not be read. Try a different one.';
    };
    reader.readAsDataURL(file);
}

function removeSlot(index) {
    photos.value.splice(index, 1);
    imageError.value = '';
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
            if (photosChanged.value) {
                body.images = photos.value.map((slot) =>
                    slot.kind === 'existing' ? `keep:${slot.index}` : slot.dataUrl,
                );
            }
            const res = await post('wishlist_update', body);
            emit('saved', res.item ?? null, 'edit');
        } else {
            const body = { ...payload };
            if (photos.value.length) {
                body.images = photos.value.map((slot) => slot.dataUrl);
            }
            const res = await post('wishlist_add', body);
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
                    <span class="field-label">Photos (optional, up to 3)</span>
                    <div class="wish-photo-slots">
                        <div v-for="(slot, i) in photos" :key="i" class="wish-photo-slot">
                            <img :src="slot.src" alt="Wish photo" />
                            <span v-if="i === 0" class="wish-photo-cover">Cover</span>
                            <button
                                type="button"
                                class="icon-btn wish-photo-slot-x"
                                aria-label="Remove this photo"
                                @click="removeSlot(i)"
                            >
                                <X :size="14" />
                            </button>
                            <button
                                type="button"
                                class="wish-photo-slot-replace"
                                @click="openFilePicker(i)"
                            >
                                Replace
                            </button>
                        </div>
                        <button
                            v-if="photos.length < MAX_PHOTOS"
                            type="button"
                            class="wish-photo-add"
                            @click="openFilePicker()"
                        >
                            <ImagePlus :size="20" />
                            <span>Add photo</span>
                        </button>
                    </div>
                    <p class="small muted" style="margin: 8px 0 0">
                        PNG, JPEG, GIF, or WebP, up to 10 MB each. The first photo
                        is the one shown on the board.
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
.wish-photo-slots {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.wish-photo-slot {
    position: relative;
    width: 92px;
    height: 92px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--card-border);
    flex: none;
}

.wish-photo-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.wish-photo-cover {
    position: absolute;
    left: 6px;
    top: 6px;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(20, 18, 46, 0.85);
    color: #f7e8c9;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.wish-photo-slot-x {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    background: rgba(20, 18, 46, 0.8);
    color: #ffffff;
}

.wish-photo-slot-replace {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 4px 0;
    border: 0;
    background: rgba(20, 18, 46, 0.78);
    color: #ffffff;
    font-size: 0.7rem;
    font-weight: 700;
    cursor: pointer;
}

.wish-photo-add {
    width: 92px;
    height: 92px;
    border-radius: 12px;
    border: 1.5px dashed rgba(196, 39, 251, 0.55);
    background: rgba(196, 39, 251, 0.07);
    color: var(--text-secondary);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    flex: none;
}
</style>
