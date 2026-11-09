<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Gift, ListChecks } from 'lucide-vue-next';
import ConfirmModal from '../../Components/ConfirmModal.vue';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import WishFormModal from '../../Components/WishFormModal.vue';
import WishlistGrid from '../../Components/WishlistGrid.vue';
import WishQuickView from '../../Components/WishQuickView.vue';
import WishReels from '../../Components/WishReels.vue';
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
});

const loading = ref(true);
const boardParty = ref(undefined);
const members = ref([]);
// 'recipient' shows only the wishlist of the person the guest is buying
// for; 'mine' is the guest's own list (it used to be its own page, the
// dock and home links land here now); 'all' is the combined grid.
const view = ref('recipient');
const selectedId = ref(null);
const reelsOpen = ref(false);
const reelsSequence = ref([]);
const reelsStartIndex = ref(0);

const party = computed(() =>
    boardParty.value === undefined ? props.party : boardParty.value,
);

// Every wish from every member in one list, each item tagged with its
// wisher (owner) and whether it belongs to the signed-in guest (mine).
// Client-side only: the wishlist_list payload already carries the items.
const allItems = computed(() => {
    const combined = [];
    for (const member of members.value) {
        const mine = String(member.id) === String(props.guest?.id);
        for (const item of member.items || []) {
            combined.push({
                ...item,
                owner: {
                    id: member.id,
                    secret_name: member.secret_name || null,
                },
                mine,
            });
        }
    }
    return combined;
});

const wishPeopleCount = computed(
    () => members.value.filter((member) => (member.items || []).length > 0).length,
);

const allCountsLine = computed(() => {
    const wishes = allItems.value.length;
    const people = wishPeopleCount.value;
    return `${wishes} wish${wishes === 1 ? '' : 'es'} from ${people} ${people === 1 ? 'person' : 'people'}`;
});

const selectedItem = computed(
    () => allItems.value.find((item) => String(item.id) === String(selectedId.value)) || null,
);

// The reels keep the exact sequence the guest saw in the filtered and
// sorted grid, while each entry is resolved back to the live combined
// item so the viewer always reflects the latest posted wish details.
const reelsItems = computed(() =>
    reelsSequence.value.map(
        (snapshot) =>
            allItems.value.find((item) => String(item.id) === String(snapshot.id)) || snapshot,
    ),
);

function openQuickView(item) {
    selectedId.value = item.id;
}

function openReels(item, visibleItems) {
    const sequence = Array.isArray(visibleItems) && visibleItems.length ? visibleItems : [item];
    const index = sequence.findIndex((candidate) => String(candidate.id) === String(item.id));
    reelsSequence.value = sequence;
    reelsStartIndex.value = index === -1 ? 0 : index;
    reelsOpen.value = true;
}

function closeReels() {
    reelsOpen.value = false;
}

async function loadBoard() {
    try {
        const body = await post('wishlist_list');
        boardParty.value = body.party || null;
        members.value = body.guests || [];
    } catch (err) {
        toastError(err.message || 'The wishlists could not be loaded. Please try again.');
        members.value = [];
    } finally {
        loading.value = false;
    }
}

// The recipient mode has its own action: the board payload never says
// who anyone's recipient is, so this is the only way the page can learn
// whose wishlist the signed-in guest is shopping for.
const recipientStatus = ref('idle');
const recipient = ref(null);
const recipientItems = ref([]);
const recipientSelectedId = ref(null);

const recipientCountsLine = computed(() => {
    const count = recipientItems.value.length;
    return `${count} wish${count === 1 ? '' : 'es'} from your recipient`;
});

const recipientSelectedItem = computed(
    () =>
        recipientItems.value.find(
            (item) => String(item.id) === String(recipientSelectedId.value),
        ) || null,
);

function openRecipientQuickView(item) {
    recipientSelectedId.value = item.id;
}

async function loadRecipient() {
    recipientStatus.value = 'loading';
    try {
        const body = await post('guest_recipient_wishlist');
        recipient.value = body.recipient || null;
        recipientItems.value = body.items || [];
        recipientStatus.value = 'ready';
    } catch (err) {
        if (err.code === 'not_revealed') {
            recipientStatus.value = 'not_revealed';
        } else if (err.code === 'not_claimed') {
            toastError('Your session has ended. Enter your name and PIN to continue.');
            router.visit('/enter');
        } else {
            recipientStatus.value = 'error';
            toastError(
                err.message || "Your recipient's wishlist could not be loaded. Please try again.",
            );
        }
    }
}

function selectView(next) {
    view.value = next;
    if (
        next === 'recipient' &&
        (recipientStatus.value === 'idle' || recipientStatus.value === 'error')
    ) {
        loadRecipient();
    }
}

// ---------------------------------------------------------------------
// My wishes mode: the guest's own list, with the add/edit/delete
// machinery the old My wishes page had. Own items come from the same
// board payload (the entry whose id is the session guest).
// ---------------------------------------------------------------------

const myEntry = computed(() =>
    members.value.find((member) => String(member.id) === String(props.guest?.id)),
);
const myItems = computed(() => myEntry.value?.items || []);

const busyId = ref(null);
const formOpen = ref(false);
const editingItem = ref(null);
const deleteTarget = ref(null);
const mineSelectedId = ref(null);

const mineSelectedItem = computed(
    () => myItems.value.find((item) => String(item.id) === String(mineSelectedId.value)) || null,
);

function openMineQuickView(item) {
    mineSelectedId.value = item.id;
}

function openAddForm() {
    // The three-wish cap: the server refuses a fourth wish anyway, so
    // say so here instead of opening a form that cannot save.
    if (myItems.value.length >= 3) {
        toastError('You can post up to three wishes. Delete one to add another.');
        return;
    }
    editingItem.value = null;
    formOpen.value = true;
}

function openEditForm(item) {
    // The quick view closes so the form is the only popup on screen; the
    // grid underneath already shows the saved changes in place.
    mineSelectedId.value = null;
    editingItem.value = item;
    formOpen.value = true;
}

function closeForm() {
    formOpen.value = false;
    editingItem.value = null;
}

function patchItem(updated) {
    for (const member of members.value) {
        const items = member.items || [];
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
        await loadBoard();
    }
}

async function deleteItem(item) {
    if (busyId.value) return;
    busyId.value = item.id;
    try {
        await post('wishlist_delete', { id: item.id });
        if (String(mineSelectedId.value) === String(item.id)) mineSelectedId.value = null;
        toastSuccess('Wish deleted');
        await loadBoard();
    } catch (err) {
        if (err.code === 'wish_not_found') {
            toastError('That wish is already gone.');
            await loadBoard();
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

onMounted(async () => {
    // Deep links: ?view=mine opens the My wishes view (the home card
    // and the old /guest/gifts address land here); ?add=1 is the dock
    // centre button, which opens the add form on top of it. Both are
    // one-shot: the parameters are stripped from the address bar as
    // soon as they are read, so a later remount, refresh, or return
    // visit on the same URL never reopens the form unasked.
    const params = new URLSearchParams(window.location.search);
    if (params.has('view') || params.has('add')) {
        const clean = new URL(window.location.href);
        clean.searchParams.delete('view');
        clean.searchParams.delete('add');
        window.history.replaceState(window.history.state, '', clean);
    }
    if (params.get('view') === 'mine' || params.get('add') === '1') {
        view.value = 'mine';
    }
    loadRecipient();
    // The board loads before the add form opens so openAddForm can
    // apply the three-wish cap against the guest's real items.
    await loadBoard();
    if (params.get('add') === '1') {
        openAddForm();
    }
});
</script>

<template>
    <Head title="Everyone's wishlists" />
    <GuestLayout dock wide>
        <!-- Scor stands on the page background, outside any card. -->
        <Mascot message="Hunting for gift ideas?" />

        <div class="slide-in" style="margin-top: 10px">
            <p v-if="loading" class="muted">Loading wishlists...</p>

            <div v-else-if="!party" class="empty-state">
                <ListChecks :size="40" />
                <p class="empty-state-title">No party set up yet</p>
                <p class="empty-state-text">
                    The host needs to set it up first. Check back once they have
                    shared the invite.
                </p>
            </div>

            <div v-else-if="members.length === 0" class="empty-state">
                <ListChecks :size="40" />
                <p class="empty-state-title">No guests yet</p>
                <p class="empty-state-text">
                    Once the host adds the guest list, everyone's wishlists will
                    appear here.
                </p>
            </div>

            <template v-else>
                <div class="view-toggle" role="group" aria-label="Choose how to browse wishlists">
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: view === 'recipient' }"
                        @click="selectView('recipient')"
                    >
                        My recipient
                    </button>
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: view === 'mine' }"
                        @click="selectView('mine')"
                    >
                        My wishes
                    </button>
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: view === 'all' }"
                        @click="selectView('all')"
                    >
                        All wishes
                    </button>
                </div>

                <template v-if="view === 'recipient'">
                    <p
                        v-if="recipientStatus === 'idle' || recipientStatus === 'loading'"
                        class="muted"
                    >
                        Loading your recipient's wishlist...
                    </p>

                    <div v-else-if="recipientStatus === 'not_revealed'" class="empty-state">
                        <Gift :size="40" />
                        <p class="empty-state-title">You have not revealed your recipient yet</p>
                        <p class="empty-state-text">
                            The wishlist of the person you are buying for will
                            appear here once you have revealed who they are.
                        </p>
                        <Link href="/reveal" class="btn btn-primary" style="margin-top: 16px">
                            <span>Reveal my recipient</span>
                        </Link>
                    </div>

                    <div v-else-if="recipientStatus === 'error'" class="empty-state">
                        <Gift :size="40" />
                        <p class="empty-state-title">This wishlist could not be loaded</p>
                        <p class="empty-state-text">
                            Check your connection and try again.
                        </p>
                        <button
                            type="button"
                            class="btn btn-primary"
                            style="margin-top: 16px"
                            @click="loadRecipient"
                        >
                            <span>Try again</span>
                        </button>
                    </div>

                    <template v-else>
                        <p
                            v-if="recipientItems.length"
                            class="muted counts-line"
                            style="margin: 0 0 14px"
                        >
                            {{ recipientCountsLine }}
                        </p>

                        <WishlistGrid
                            v-if="recipientItems.length"
                            :items="recipientItems"
                            mode="member"
                            @open="openRecipientQuickView"
                        />
                        <div v-else class="empty-state">
                            <Gift :size="40" />
                            <p class="empty-state-title">
                                Your recipient has not posted any wishes yet
                            </p>
                            <p class="empty-state-text">
                                Any wishes they post will appear here.
                            </p>
                        </div>

                        <WishQuickView
                            :item="recipientSelectedItem"
                            mode="member"
                            @close="recipientSelectedId = null"
                        />
                    </template>
                </template>

                <template v-else-if="view === 'mine'">
                    <WishlistGrid
                        :items="myItems"
                        mode="mine"
                        add-card
                        :busy-id="busyId"
                        @add="openAddForm"
                        @delete="deleteItem"
                        @edit="openEditForm"
                        @open="openMineQuickView"
                    />

                    <WishQuickView
                        :item="mineSelectedItem"
                        mode="mine"
                        :busy="busyId !== null"
                        @close="mineSelectedId = null"
                        @delete="requestDelete"
                        @edit="openEditForm"
                    />
                </template>

                <template v-else>
                    <template v-if="allItems.length">
                        <p class="muted counts-line" style="margin: 0 0 14px">{{ allCountsLine }}</p>
                        <WishlistGrid
                            :items="allItems"
                            mode="member"
                            @open="openReels"
                        />
                    </template>
                    <div v-else class="empty-state">
                        <Gift :size="40" />
                        <p class="empty-state-title">No wishes yet</p>
                        <p class="empty-state-text">
                            Nobody has added a wish yet. Be the first to add one,
                            so your Secret Santa knows where to start.
                        </p>
                    </div>
                </template>

                <WishQuickView
                    :item="selectedItem"
                    mode="member"
                    @close="selectedId = null"
                />

                <WishReels
                    v-if="reelsOpen"
                    :items="reelsItems"
                    :start-index="reelsStartIndex"
                    @close="closeReels"
                />
            </template>
        </div>

        <WishFormModal
            v-if="formOpen"
            :item="editingItem"
            @close="closeForm"
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
.view-toggle {
    display: flex;
    width: fit-content;
    margin-inline: auto;
    gap: 4px;
    padding: 4px;
    margin-bottom: 18px;
    border-radius: 999px;
    border: 1px solid var(--card-border);
    background: var(--glass);
}

.view-toggle-btn {
    min-height: 36px;
    padding: 7px 16px;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.86rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.view-toggle-btn:hover {
    color: var(--text-primary);
}

.view-toggle-btn.active {
    background: rgba(196, 39, 251, 0.18);
    color: #f3b8ff;
}

/* Phone size pass: the My recipient / All wishes toggle reads smaller. */
@media (max-width: 767.98px) {
    .view-toggle {
        padding: 3px;
        margin-bottom: 14px;
    }

    .view-toggle-btn {
        min-height: 32px;
        padding: 6px 14px;
        font-size: 0.78rem;
    }
}
</style>
