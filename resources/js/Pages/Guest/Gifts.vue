<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Gift } from 'lucide-vue-next';
import ConfirmModal from '../../Components/ConfirmModal.vue';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import WishFormModal from '../../Components/WishFormModal.vue';
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

const loading = ref(true);
const board = ref([]);
const busyId = ref(null);
const selectedId = ref(null);

// Wish form (add and edit share the modal; editingItem null means add)
const formOpen = ref(false);
const editingItem = ref(null);
const deleteTarget = ref(null);

const myEntry = computed(() =>
    board.value.find((entry) => String(entry.id) === String(props.guest?.id)),
);
const myItems = computed(() => myEntry.value?.items || []);

const selectedItem = computed(
    () => myItems.value.find((item) => String(item.id) === String(selectedId.value)) || null,
);

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

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('add') === '1') {
        openAddForm();
    }
    loadList();
});

function openAddForm() {
    editingItem.value = null;
    formOpen.value = true;
}

function openEditForm(item) {
    // The quick view closes so the form is the only popup on screen; the
    // grid underneath already shows the saved changes in place.
    selectedId.value = null;
    editingItem.value = item;
    formOpen.value = true;
}

function closeForm() {
    formOpen.value = false;
    editingItem.value = null;
}

function patchItem(updated) {
    for (const entry of board.value) {
        const items = entry.items || [];
        const index = items.findIndex((item) => String(item.id) === String(updated.id));
        if (index >= 0) {
            items.splice(index, 1, { ...items[index], ...updated });
            return;
        }
    }
}

async function onFormSaved(savedItem, mode) {
    closeForm();
    if (mode === 'edit' && savedItem) {
        patchItem(savedItem);
        toastSuccess('Changes saved');
    } else {
        toastSuccess('Wish added');
        await loadList();
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
    <Head title="My wishes" />
    <GuestLayout dock wide>
        <!-- No party: a normal empty state, not an error. -->
        <div v-if="!party" class="card slide-in">
            <span class="section-label">My wishes</span>
            <h1 class="card-title">My wishes</h1>
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
            <!-- Scor stands on the page background, outside any card. -->
            <Mascot message="What would make you smile?" />

            <!-- Wishes live below, in the shopping grid. -->
            <div class="slide-in" style="margin-top: 10px">
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
                    @edit="openEditForm"
                    @open="openQuickView"
                />
            </div>
        </template>

        <WishFormModal
            v-if="formOpen"
            :item="editingItem"
            @close="closeForm"
            @saved="onFormSaved"
        />

        <WishQuickView
            :item="selectedItem"
            mode="mine"
            :busy="busyId !== null"
            @close="selectedId = null"
            @delete="requestDelete"
            @edit="openEditForm"
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
