<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ChevronRight, ListChecks, Search } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import { post } from '../../lib/api';
import { normalizeText } from '../../lib/normalize';
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
const query = ref('');

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
        </div>
    </GuestLayout>
</template>
