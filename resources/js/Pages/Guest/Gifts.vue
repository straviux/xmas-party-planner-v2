<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, Gift, ImagePlus, Plus, X } from 'lucide-vue-next';
import ConfirmModal from '../../Components/ConfirmModal.vue';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import WishlistGrid from '../../Components/WishlistGrid.vue';
import WishQuickView from '../../Components/WishQuickView.vue';
import { post } from '../../lib/api';
import { toastError, toastSuccess } from '../../lib/toast';

const props = defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
    counts: {
        type: Object,
        default: () => ({ locked: 0, total: 0, wishes: 0 }),
    },
});

const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

const loading = ref(true);
const board = ref([]);
const busyId = ref(null);
const selectedId = ref(null);

// Add form
const showForm = ref(false);
const submitting = ref(false);
const formError = ref('');
const text = ref('');
const link = ref('');
const notes = ref('');
const imageData = ref('');
const imageError = ref('');
const fileInput = ref(null);
const wishTextInput = ref(null);
const deleteTarget = ref(null);

const myEntry = computed(() =>
    board.value.find((entry) => String(entry.id) === String(props.guest?.id)),
);
const myItems = computed(() => myEntry.value?.items || []);

const selectedItem = computed(
    () => myItems.value.find((item) => String(item.id) === String(selectedId.value)) || null,
);

const ADD_ERRORS = {
    wish_empty: 'Add a short description, a link, or a screenshot, at least one.',
    image_too_large: 'That image is too large to save. Try a smaller screenshot.',
    image_invalid: 'That image could not be saved. Try a different screenshot.',
    not_claimed: 'You need to enter the party first before adding wishes.',
};

async function loadList() {
    try {
        const body = await post('wishlist_list');
        board.value = body.guests || [];
    } catch (err) {
        toastError(err.message || 'Your wishlist could not be loaded. Please try again.');
        board.value = [];
    } finally {
        loading.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && showForm.value && !submitting.value && !deleteTarget.value) {
        closeAddForm();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if (new URLSearchParams(window.location.search).get('add') === '1') {
        openAddForm();
    }
    loadList();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});

async function openAddForm() {
    showForm.value = true;
    await nextTick();
    wishTextInput.value?.focus();
}

function closeAddForm() {
    if (submitting.value) return;
    showForm.value = false;
}

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
    };
    reader.onerror = () => {
        imageError.value = 'That image could not be read. Try a different one.';
    };
    reader.readAsDataURL(file);
}

function removeImage() {
    imageData.value = '';
    imageError.value = '';
}

function resetForm() {
    text.value = '';
    link.value = '';
    notes.value = '';
    imageData.value = '';
    imageError.value = '';
    formError.value = '';
}

async function submitWish() {
    formError.value = '';
    const payload = {
        text: text.value.trim() || null,
        link: link.value.trim() || null,
        notes: notes.value.trim() || null,
        image: imageData.value || null,
    };
    if (!payload.text && !payload.link && !payload.image) {
        formError.value = ADD_ERRORS.wish_empty;
        return;
    }
    submitting.value = true;
    try {
        await post('wishlist_add', payload);
        toastSuccess('Wish added');
        resetForm();
        showForm.value = false;
        await loadList();
    } catch (err) {
        formError.value =
            ADD_ERRORS[err.code] || err.message || 'That wish could not be added. Please try again.';
    } finally {
        submitting.value = false;
    }
}

async function deleteItem(item) {
    if (busyId.value) return;
    busyId.value = item.id;
    try {
        await post('wishlist_delete', { id: item.id });
        if (String(selectedId.value) === String(item.id)) selectedId.value = null;
        toastSuccess('Wish deleted');
        await loadList();
    } catch (err) {
        if (err.code === 'wish_not_found') {
            toastError('That wish is already gone.');
            await loadList();
        } else if (err.code === 'forbidden') {
            toastError('You can only delete your own wishes.');
        } else {
            toastError(err.message || 'That wish could not be deleted. Please try again.');
        }
    } finally {
        busyId.value = null;
    }
}

function wishDisplayName(item) {
    if (!item) return 'This wish';
    if (item.text) return item.text;
    if (item.kind === 'image') return 'Screenshot wish';
    if (item.kind === 'link' || item.link) return 'Link wish';
    return 'Untitled wish';
}

function requestDelete(item) {
    deleteTarget.value = item;
}

async function confirmDeleteWish() {
    const item = deleteTarget.value;
    if (!item) return;
    await deleteItem(item);
    deleteTarget.value = null;
}

function openQuickView(item) {
    selectedId.value = item.id;
}
</script>

<template>
    <Head title="My wishlist" />
    <GuestLayout dock wide>
        <!-- No party: a normal empty state, not an error. -->
        <div v-if="!party" class="card slide-in">
            <span class="section-label">My wishlist</span>
            <h1 class="card-title">My wishlist</h1>
            <div class="empty-state">
                <Gift :size="40" />
                <p class="empty-state-title">No party set up yet</p>
                <p class="empty-state-text">
                    The host needs to set it up first. Check back once they have
                    shared the invite.
                </p>
            </div>
        </div>

        <template v-else>
            <!-- Header card: mascot, title, explainer, add form. -->
            <div class="card slide-in">
                <Mascot message="What would make you smile?" />
                <div style="text-align: center; margin-top: 10px">
                    <span class="section-label">My wishlist</span>
                    <h1 class="card-title">What are you wishing for?</h1>
                    <p class="card-subtitle" style="max-width: 520px; margin: 0 auto">
                        Add the gifts you would love to receive. Everyone at the party
                        can see your list, and your Secret Santa will thank you for
                        the hints.
                    </p>
                    <button
                        type="button"
                        class="btn btn-primary"
                        style="margin-top: 18px"
                        @click="openAddForm"
                    >
                        <Plus :size="18" />
                        <span>Add a wish</span>
                    </button>
                </div>


            </div>

            <!-- Wishes live below the header card, in the shopping grid. -->
            <div style="margin-top: 22px">
                <p v-if="loading" class="muted">Loading your wishes...</p>

                <div v-else-if="myItems.length === 0" class="card">
                    <div class="empty-state">
                        <Gift :size="40" />
                        <p class="empty-state-title">No wishes yet</p>
                        <p class="empty-state-text">
                            Tap + to add your first one, so your Secret Santa knows
                            what to look for.
                        </p>
                    </div>
                </div>

                <WishlistGrid
                    v-else
                    :items="myItems"
                    mode="mine"
                    :busy-id="busyId"
                    @delete="deleteItem"
                    @open="openQuickView"
                />
            </div>
        </template>

        <div v-if="showForm" class="modal-backdrop" @click.self="closeAddForm">
            <div
                class="modal-card pop-in"
                style="width: min(94vw, 560px)"
                role="dialog"
                aria-modal="true"
                aria-label="Add a wish"
            >
                <h3 class="modal-title">Add a wish</h3>
                <form style="margin-top: 14px" @submit.prevent="submitWish">
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
                        <div v-if="imageData" class="wish-preview">
                            <img :src="imageData" alt="Your screenshot preview" />
                            <button
                                type="button"
                                class="icon-btn wish-preview-remove"
                                aria-label="Remove screenshot"
                                @click="removeImage"
                            >
                                <X :size="17" />
                            </button>
                        </div>
                        <template v-else>
                            <button type="button" class="btn btn-secondary" @click="openFilePicker">
                                <ImagePlus :size="18" />
                                <span>Add a screenshot</span>
                            </button>
                            <p class="small muted" style="margin: 8px 0 0">
                                PNG, JPEG, GIF, or WebP, up to 10 MB.
                            </p>
                        </template>
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
                        <button type="button" class="btn btn-secondary" :disabled="submitting" @click="closeAddForm">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="submitting">
                            <Plus :size="18" />
                            <span>{{ submitting ? 'Adding...' : 'Add to my wishlist' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <WishQuickView
            :item="selectedItem"
            mode="mine"
            :busy="busyId !== null"
            @close="selectedId = null"
            @delete="requestDelete"
        />

        <ConfirmModal
            v-if="deleteTarget"
            title="Delete this wish?"
            :body="`${wishDisplayName(deleteTarget)} will be deleted. This cannot be undone.`"
            confirm-label="Delete wish"
            cancel-label="Keep wish"
            danger
            :busy="busyId !== null"
            @confirm="confirmDeleteWish"
            @cancel="deleteTarget = null"
        />
    </GuestLayout>
</template>
