<script setup>
import { computed, ref } from 'vue';
import { ExternalLink, Search } from 'lucide-vue-next';
import ConfirmModal from './ConfirmModal.vue';
import WishActionsMenu from './WishActionsMenu.vue';
import { normalizeText } from '../lib/normalize';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    // 'mine' shows the settings menu (edit/delete); other modes browse.
    mode: {
        type: String,
        default: 'member',
    },
    // Id of the item currently being deleted, if any.
    busyId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(['delete', 'edit', 'open']);

const query = ref('');
const filter = ref('all');
const sort = ref('newest');
const broken = ref({});
const deleteTarget = ref(null);

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'photos', label: 'With photos' },
    { value: 'text', label: 'Text only' },
];

function hasImage(item) {
    return Boolean(item.image) && !broken.value[item.id];
}

function markBroken(item) {
    broken.value = { ...broken.value, [item.id]: true };
}

function displayName(item) {
    if (item.text) return item.text;
    if (item.kind === 'image') return 'Screenshot wish';
    if (item.kind === 'link' || item.link) return 'Link wish';
    return 'Untitled wish';
}

const visibleItems = computed(() => {
    const q = normalizeText(query.value);
    const withIndex = props.items.map((item, index) => ({ item, index }));

    const filtered = withIndex.filter(({ item }) => {
        if (q) {
            const parts = [item.text, item.notes, item.link];
            // Combined views attach the wisher; searching their secret
            // name finds their wishes too. Absent elsewhere, so inert.
            if (item.owner) parts.push(item.owner.secret_name);
            const haystack = normalizeText(parts.filter(Boolean).join(' '));
            if (!haystack.includes(q)) return false;
        }
        switch (filter.value) {
            case 'photos':
                return item.kind === 'image' && hasImage(item);
            case 'text':
                return item.kind !== 'image' || !hasImage(item);
            default:
                return true;
        }
    });

    const dateOf = (item) => {
        const parsed = item.created_at ? Date.parse(item.created_at) : NaN;
        return Number.isNaN(parsed) ? null : parsed;
    };

    filtered.sort((a, b) => {
        if (sort.value === 'az' || sort.value === 'za') {
            const order = displayName(a.item).localeCompare(displayName(b.item));
            return sort.value === 'az' ? order : -order;
        }
        const da = dateOf(a.item);
        const db = dateOf(b.item);
        if (da !== null && db !== null && da !== db) {
            return sort.value === 'newest' ? db - da : da - db;
        }
        // Items arrive newest first from the server.
        return sort.value === 'newest' ? a.index - b.index : b.index - a.index;
    });

    return filtered.map(({ item }) => item);
});

function handleAction(item) {
    deleteTarget.value = item;
}

function confirmDelete() {
    const item = deleteTarget.value;
    deleteTarget.value = null;
    if (item) emit('delete', item);
}
</script>

<template>
    <div>
        <div class="wish-controls">
            <div class="chip-row">
                <button
                    v-for="chip in FILTERS"
                    :key="chip.value"
                    type="button"
                    class="chip"
                    :class="{ active: filter === chip.value }"
                    @click="filter = chip.value"
                >
                    {{ chip.label }}
                </button>
                <select v-model="sort" class="select sort-select" aria-label="Sort wishes">
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="az">A-Z</option>
                    <option value="za">Z-A</option>
                </select>
            </div>
            <label class="wish-search">
                <Search :size="18" />
                <input
                    v-model="query"
                    class="input"
                    type="text"
                    autocomplete="off"
                    placeholder="Search wishes"
                    aria-label="Search wishes"
                />
            </label>
        </div>

        <div v-if="visibleItems.length" class="wish-grid">
            <article v-for="item in visibleItems" :key="item.id" class="wish-tile">
                <div v-if="hasImage(item)" class="wish-tile-media" @click="emit('open', item, visibleItems)">
                    <img :src="item.image" :alt="displayName(item)" loading="lazy" @error="markBroken(item)" />
                </div>
                <div class="wish-tile-body">
                    <div
                        :class="{ 'wish-open-area': !hasImage(item) }"
                        @click="!hasImage(item) && emit('open', item, visibleItems)"
                    >
                        <p class="wish-name clamp-2">{{ displayName(item) }}</p>
                        <p v-if="item.notes" class="wish-notes clamp-3">{{ item.notes }}</p>
                        <p v-if="item.owner" class="wish-owner">
                            by <template v-if="item.owner.secret_name">{{ item.owner.secret_name }}</template><span
                                v-else
                                class="wish-owner-placeholder"
                                role="img"
                                aria-label="Secret name not picked yet"
                            >???</span>
                        </p>
                    </div>
                    <div v-if="item.link || item.mine || props.mode === 'mine'" class="wish-tile-actions">
                        <a
                            v-if="item.link"
                            class="icon-btn wish-tile-link"
                            :href="item.link"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="`Open the shop link for ${displayName(item)}`"
                            @click.stop
                        >
                            <ExternalLink :size="16" />
                        </a>
                        <span v-if="item.mine" class="wish-mine-chip">Me</span>
                        <WishActionsMenu
                            v-else-if="props.mode === 'mine'"
                            class="wish-tile-settings"
                            :item="item"
                            :busy="props.busyId === item.id"
                            @edit="emit('edit', $event)"
                            @delete="handleAction"
                        />
                    </div>
                </div>
            </article>
        </div>

        <p v-else class="muted" style="margin: 6px 0 0">
            No wishes match that search. Try a different word or filter.
        </p>

        <ConfirmModal
            v-if="deleteTarget"
            title="Delete this wish?"
            :body="`${displayName(deleteTarget)} will be deleted. This cannot be undone.`"
            confirm-label="Delete wish"
            cancel-label="Keep wish"
            danger
            @confirm="confirmDelete"
            @cancel="deleteTarget = null"
        />
    </div>
</template>

<style scoped>
/* Only combined views attach owner/mine to items; these styles are unused
   by every existing single-owner usage of the grid. */
.wish-owner {
    margin: 6px 0 0;
    font-size: 0.8rem;
    color: var(--text-tertiary);
}

/* Same muted italic ??? as the reveal and recipient waiting states, so
   an unpicked secret name never reads as an actual name. */
.wish-owner-placeholder {
    font-style: italic;
    letter-spacing: 0.04em;
}

.wish-mine-chip {
    display: inline-flex;
    align-items: center;
    min-height: 36px;
    padding: 8px 13px;
    border-radius: 999px;
    border: 1px solid var(--card-border);
    background: var(--glass);
    color: var(--text-secondary);
    font-size: 0.8rem;
    font-weight: 700;
}

/* Phone size pass: tile tags read smaller on small screens. */
@media (max-width: 767.98px) {
    .wish-owner {
        font-size: 0.73rem;
    }

    .wish-mine-chip {
        min-height: 34px;
        padding: 7px 11px;
        font-size: 0.72rem;
    }
}
</style>
