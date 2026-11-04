<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, Gift, ListChecks, Plus, Search } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import WishlistGrid from '../../Components/WishlistGrid.vue';
import WishQuickView from '../../Components/WishQuickView.vue';
import WishReels from '../../Components/WishReels.vue';
import { post } from '../../lib/api';
import { normalizeText } from '../../lib/normalize';
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
const query = ref('');
// 'people' is the original member list; 'all' is the combined wishes grid.
const view = ref('people');
const busyId = ref(null);
const selectedId = ref(null);
const reelsOpen = ref(false);
const reelsSequence = ref([]);
const reelsStartIndex = ref(0);

const party = computed(() =>
    boardParty.value === undefined ? props.party : boardParty.value,
);

function wishCount(member) {
    return member.wish_count ?? member.items?.length ?? 0;
}

function memberMatches(member, rawQuery) {
    const q = normalizeText(rawQuery);
    if (!q) return true;
    const fields = [member.display, member.nickname, ...(member.aliases || [])]
        .map(normalizeText)
        .filter(Boolean);
    return fields.some((field) => field.includes(q));
}

const visibleMembers = computed(() =>
    members.value.filter((member) => memberMatches(member, query.value)),
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
                    display: member.display,
                    nickname: member.nickname || null,
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
// item so a reserve made inside the viewer updates in place.
const reelsItems = computed(() =>
    reelsSequence.value.map(
        (snapshot) =>
            allItems.value.find((item) => String(item.id) === String(snapshot.id)) || snapshot,
    ),
);

const RESERVE_ERRORS = {
    already_reserved: 'Someone else has already reserved this wish.',
    cannot_reserve_own: 'You cannot reserve your own wish.',
    not_claimed: 'You need to enter the party before you can reserve a wish.',
    wish_not_found: 'That wish is not there anymore.',
    forbidden: 'That wish cannot be reserved.',
};

function applyReserveResult(item, body) {
    for (const entry of members.value) {
        if (!entry.items) continue;
        const index = entry.items.findIndex((candidate) => String(candidate.id) === String(item.id));
        if (index !== -1) {
            entry.items[index] = {
                ...entry.items[index],
                reserved: Boolean(body.reserved),
                reserved_by_me: Boolean(body.reserved_by_me),
            };
            return;
        }
    }
}

async function toggleReserve(item) {
    if (busyId.value) return;
    busyId.value = item.id;
    try {
        const body = await post('wishlist_reserve', { id: item.id });
        applyReserveResult(item, body);
        toastSuccess(body.reserved_by_me ? 'Wish reserved' : 'Reservation removed');
    } catch (err) {
        toastError(
            RESERVE_ERRORS[err.code] ||
                err.message ||
                'That reservation could not be changed. Please try again.',
        );
        // Keep the grid honest if someone else changed the state first.
        await loadBoard();
    } finally {
        busyId.value = null;
    }
}

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

onMounted(loadBoard);

function openMember(member) {
    router.visit(`/guest/wishlists/${member.id}`);
}
</script>

<template>
    <Head title="Everyone's wishlists" />
    <GuestLayout dock wide>
        <div class="card slide-in">
            <span class="section-label">Wishlists</span>
            <h1 class="card-title">Everyone's wishlists</h1>
            <p class="card-subtitle" style="margin-bottom: 20px">
                Find a person to see what they are wishing for. If a wish is
                marked Reserved, someone is already on it, and it stays
                anonymous.
            </p>

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
                        :class="{ active: view === 'people' }"
                        @click="view = 'people'"
                    >
                        By person
                    </button>
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: view === 'all' }"
                        @click="view = 'all'"
                    >
                        All wishes
                    </button>
                </div>

                <template v-if="view === 'people'">
                <label class="wish-search" style="margin-bottom: 16px">
                    <Search :size="18" />
                    <input
                        v-model="query"
                        class="input"
                        type="text"
                        autocomplete="off"
                        placeholder="Search by name, nickname, or alias"
                        aria-label="Search people"
                    />
                </label>

                <div v-if="visibleMembers.length" class="member-list">
                    <button
                        v-for="member in visibleMembers"
                        :key="member.id"
                        type="button"
                        class="member-row"
                        @click="openMember(member)"
                    >
                        <span class="member-row-main">
                            <span class="member-row-name">
                                {{ member.display }}
                                <span
                                    v-if="String(member.id) === String(guest?.id)"
                                    class="badge badge-locked"
                                >
                                    You
                                </span>
                            </span>
                            <span v-if="member.nickname" class="member-row-sub">
                                “{{ member.nickname }}”
                            </span>
                            <span
                                v-if="member.aliases && member.aliases.length"
                                class="member-row-sub"
                            >
                                (also: {{ member.aliases.join(', ') }})
                            </span>
                        </span>
                        <span class="member-row-side">
                            <span>
                                {{
                                    wishCount(member) > 0
                                        ? `${wishCount(member)} wish${wishCount(member) === 1 ? '' : 'es'}`
                                        : 'no wishlist yet'
                                }}
                            </span>
                            <ChevronRight :size="18" />
                        </span>
                    </button>
                </div>
                <p v-else class="muted" style="margin: 4px 0 0">
                    No matching names. Check the spelling, or try a nickname.
                </p>
                </template>

                <template v-else>
                    <template v-if="allItems.length">
                        <p class="muted" style="margin: 0 0 14px">{{ allCountsLine }}</p>
                        <WishlistGrid
                            :items="allItems"
                            mode="member"
                            :busy-id="busyId"
                            @reserve="toggleReserve"
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
                    :busy="busyId !== null"
                    @close="selectedId = null"
                    @reserve="toggleReserve"
                />

                <WishReels
                    v-if="reelsOpen"
                    :items="reelsItems"
                    :start-index="reelsStartIndex"
                    :busy="busyId !== null"
                    @close="closeReels"
                    @reserve="toggleReserve"
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
</style>
