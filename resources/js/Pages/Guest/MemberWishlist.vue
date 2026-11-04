<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Gift } from 'lucide-vue-next';
import ConfirmModal from '../../Components/ConfirmModal.vue';
import GuestLayout from '../../Components/GuestLayout.vue';
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
    member: {
        type: Object,
        default: null,
    },
});

const loading = ref(true);
const board = ref([]);
const busyId = ref(null);
const selectedId = ref(null);
const deleteTarget = ref(null);

// Edit form (own list only; the grid and quick view only offer it in
// 'mine' mode). editingItem null keeps the modal closed.
const editingItem = ref(null);

const isMine = computed(() => String(props.member?.id) === String(props.guest?.id));
const mode = computed(() => (isMine.value ? 'mine' : 'member'));

const memberEntry = computed(() =>
    board.value.find((entry) => String(entry.id) === String(props.member?.id)),
);
const items = computed(() => memberEntry.value?.items || []);

const selectedItem = computed(
    () => items.value.find((item) => String(item.id) === String(selectedId.value)) || null,
);

async function loadList() {
    try {
        const body = await post('wishlist_list');
        board.value = body.guests || [];
    } catch (err) {
        toastError(err.message || 'This wishlist could not be loaded. Please try again.');
        board.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(loadList);

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

function openQuickView(item) {
    selectedId.value = item.id;
}

function openEditForm(item) {
    // The quick view closes so the form is the only popup on screen; the
    // grid underneath already shows the saved changes in place.
    selectedId.value = null;
    editingItem.value = item;
}

function patchItem(updated) {
    for (const entry of board.value) {
        const entryItems = entry.items || [];
        const index = entryItems.findIndex((item) => String(item.id) === String(updated.id));
        if (index >= 0) {
            entryItems.splice(index, 1, { ...entryItems[index], ...updated });
            return;
        }
    }
}

function onFormSaved(savedItem) {
    editingItem.value = null;
    if (savedItem) {
        patchItem(savedItem);
        toastSuccess('Changes saved');
    }
}

function wishDisplayName(item) {
    if (!item) return 'This wish';
    if (item.text) return item.text;
    if (item.kind === 'image') return 'Screenshot wish';
    if (item.kind === 'link' || item.link) return 'Link wish';
    return 'Untitled wish';
}

// The grid confirms inside itself before emitting delete; the quick view
// emits straight away, so its deletes are gated here with the same popup.
function requestDelete(item) {
    deleteTarget.value = item;
}

async function confirmDeleteWish() {
    const item = deleteTarget.value;
    if (!item) return;
    await deleteItem(item);
    deleteTarget.value = null;
}
</script>

<template>
    <Head :title="member ? `${member.display}'s wishlist` : 'Wishlist'" />
    <GuestLayout dock wide active="wishlists">
        <div class="card slide-in">
            <Link href="/guest/wishlists" class="back-link">
                <ArrowLeft :size="15" />
                <span>All wishlists</span>
            </Link>
            <span class="section-label" style="margin-top: 14px">
                {{ isMine ? 'Your wishlist, as everyone sees it' : 'Wishlist' }}
            </span>
            <h1 class="card-title">{{ member?.display || 'Wishlist' }}</h1>
            <p class="card-subtitle">
                <template v-if="member?.nickname">“{{ member.nickname }}” · </template>
                <template v-if="loading">Loading wishes...</template>
                <template v-else-if="items.length">
                    {{ items.length }} wish{{ items.length === 1 ? '' : 'es' }} on this list
                </template>
                <template v-else>No wishes yet</template>
            </p>
            <p v-if="!isMine" class="card-subtitle" style="margin-top: 8px">
                Browse the wishes they posted and open one to see its details.
            </p>
        </div>

        <div style="margin-top: 22px">
            <div v-if="!loading && items.length === 0" class="card">
                <div class="empty-state">
                    <Gift :size="40" />
                    <p class="empty-state-title">{{ member?.display }} has no wishes yet</p>
                    <p class="empty-state-text">
                        <template v-if="isMine">
                            Add your first wish from your Gifts page, and it will
                            show up here too.
                        </template>
                        <template v-else>
                            Check back later, or ask them at the party what they
                            are hoping for.
                        </template>
                    </p>
                </div>
            </div>

            <WishlistGrid
                v-else-if="!loading"
                :items="items"
                :mode="mode"
                :busy-id="busyId"
                @delete="deleteItem"
                @edit="openEditForm"
                @open="openQuickView"
            />
        </div>

        <WishQuickView
            :item="selectedItem"
            :mode="mode"
            :busy="busyId !== null"
            @close="selectedId = null"
            @delete="requestDelete"
            @edit="openEditForm"
        />

        <WishFormModal
            v-if="editingItem"
            :item="editingItem"
            @close="editingItem = null"
            @saved="onFormSaved"
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

<style scoped>
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--lavender);
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}
</style>
