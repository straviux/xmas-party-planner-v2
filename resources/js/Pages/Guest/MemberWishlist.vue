<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Gift } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
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

const RESERVE_ERRORS = {
    already_reserved: 'Someone else has already reserved this wish.',
    cannot_reserve_own: 'You cannot reserve your own wish.',
    not_claimed: 'You need to enter the party before you can reserve a wish.',
    wish_not_found: 'That wish is not there anymore.',
};

function applyReserveResult(item, body) {
    const entry = memberEntry.value;
    if (!entry || !entry.items) return;
    const index = entry.items.findIndex((candidate) => String(candidate.id) === String(item.id));
    if (index !== -1) {
        entry.items[index] = {
            ...entry.items[index],
            reserved: Boolean(body.reserved),
            reserved_by_me: Boolean(body.reserved_by_me),
        };
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
        await loadList();
    } finally {
        busyId.value = null;
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

function openQuickView(item) {
    selectedId.value = item.id;
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
                See something you would like to get them? Tap Reserve, and it is
                quietly marked so nobody buys it twice. Nobody will see it was you.
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
                @reserve="toggleReserve"
                @delete="deleteItem"
                @open="openQuickView"
            />
        </div>

        <WishQuickView
            :item="selectedItem"
            :mode="mode"
            :busy="busyId !== null"
            @close="selectedId = null"
            @reserve="toggleReserve"
            @delete="deleteItem"
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
