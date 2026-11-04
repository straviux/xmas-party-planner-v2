<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Gift, ListChecks, Plus } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import WishlistGrid from '../../Components/WishlistGrid.vue';
import WishQuickView from '../../Components/WishQuickView.vue';
import WishReels from '../../Components/WishReels.vue';
import { post } from '../../lib/api';
import { toastError } from '../../lib/toast';

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
// for; 'all' is the combined wishes grid.
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
const recipientBudget = ref('');
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
        recipientBudget.value = body.budget || '';
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

onMounted(() => {
    loadBoard();
    loadRecipient();
});
</script>

<template>
    <Head title="Everyone's wishlists" />
    <GuestLayout dock wide>
        <!-- Header card: mascot, title, explainer (same hero as the Gifts page). -->
        <div class="card slide-in">
            <Mascot message="Hunting for gift ideas?" />
            <div style="text-align: center; margin-top: 10px">
                <span class="section-label">Wishlists</span>
                <h1 class="card-title">What is everyone wishing for?</h1>
                <p class="card-subtitle" style="max-width: 520px; margin: 0 auto">
                    Browse everyone's wishes in one place, or switch to My
                    recipient to see only the list that matters most.
                </p>
            </div>
        </div>

        <div class="card" style="margin-top: 22px">
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
                        <div class="recipient-head">
                            <p class="buying-for">
                                <span class="buying-for-label">You are buying for</span>
                                <span v-if="recipient?.secret_name" class="buying-for-name">
                                    {{ recipient.secret_name }}
                                </span>
                                <span
                                    v-else
                                    class="buying-for-placeholder"
                                    role="img"
                                    aria-label="Secret name not picked yet"
                                >???</span>
                            </p>
                            <p v-if="!recipient?.secret_name" class="muted" style="margin: 6px 0 0">
                                Your recipient has not picked a secret name yet.
                            </p>
                        </div>

                        <p v-if="recipientBudget" class="muted counts-line" style="margin: 0 0 4px">
                            Gift budget: <strong>{{ recipientBudget }}</strong>
                        </p>
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
                        <Link href="/guest/gifts?add=1" class="btn btn-primary" style="margin-top: 16px">
                            <Plus :size="18" />
                            <span>Add a wish</span>
                        </Link>
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
    </GuestLayout>
</template>

<style scoped>
.view-toggle {
    display: inline-flex;
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

.recipient-head {
    margin-bottom: 14px;
}

/* The "You are buying for" header, in the same shape as the reveal
   result: the label matches RevealStrip's under-layer label, the secret
   name sits at the revealed name's type scale, and the placeholder for
   a recipient who has not picked one yet is the reveal waiting state's
   muted italic ??? so it can never be mistaken for an actual name. */
.buying-for {
    margin: 0;
}

.buying-for-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
}

.buying-for-name {
    display: block;
    font-size: 1.38rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    line-height: 1.15;
    color: var(--text-primary);
    margin-top: 2px;
}

.buying-for-placeholder {
    display: block;
    font-size: 1.38rem;
    font-weight: 800;
    font-style: italic;
    letter-spacing: 0.04em;
    line-height: 1.15;
    color: var(--text-tertiary);
    margin-top: 2px;
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
