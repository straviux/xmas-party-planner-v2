<script setup>
import { computed, ref } from 'vue';
import { Bookmark, BookmarkCheck, ExternalLink, Search, Trash2 } from 'lucide-vue-next';
import ConfirmModal from './ConfirmModal.vue';
import { normalizeText } from '../lib/normalize';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    // 'mine' shows Delete actions; 'member' shows Reserve actions.
    mode: {
        type: String,
        default: 'member',
    },
    // Id of the item currently being reserved/deleted, if any.
    busyId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(['reserve', 'delete', 'open']);

const query = ref('');
const filter = ref('all');
const sort = ref('newest');
const broken = ref({});
const deleteTarget = ref(null);

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'photos', label: 'With photos' },
    { value: 'text', label: 'Text only' },
    { value: 'available', label: 'Available' },
    { value: 'reserved', label: 'Reserved' },
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
            // Combined views attach the wisher; searching their name or
            // nickname finds their wishes too. Absent elsewhere, so inert.
            if (item.owner) parts.push(item.owner.display, item.owner.nickname);
            const haystack = normalizeText(parts.filter(Boolean).join(' '));
            if (!haystack.includes(q)) return false;
        }
        switch (filter.value) {
            case 'photos':
                return item.kind === 'image' && hasImage(item);
            case 'text':
                return item.kind !== 'image' || !hasImage(item);
            case 'available':
                return !item.reserved;
            case 'reserved':
                return Boolean(item.reserved);
            default:
                return true;
        }
    });

    const dateOf = (item) => {
        const parsed = item.created_at ? Date.parse(item.created_at) : NaN;
        return Number.isNaN(parsed) ? null : parsed;
    };

    filtered.sort((a, b) => {
        if (sort.value === 'az') {
            return displayName(a.item).localeCompare(displayName(b.item));
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

function actionState(item) {
    if (props.mode === 'mine') {
        return {
            label: 'Delete',
            className: 'btn btn-secondary',
            icon: Trash2,
            disabled: props.busyId === item.id,
        };
    }
    if (item.reserved_by_me) {
        return {
            label: 'Reserved by you, tap to undo',
            className: 'btn btn-secondary',
            icon: BookmarkCheck,
            disabled: props.busyId === item.id,
        };
    }
    if (item.reserved) {
        return {
            label: 'Reserved',
            className: 'btn btn-secondary',
            icon: Bookmark,
            disabled: true,
        };
    }
    return {
        label: 'Reserve',
        className: 'btn btn-primary',
        icon: Bookmark,
        disabled: props.busyId === item.id,
    };
}

function handleAction(item) {
    if (props.mode === 'mine') {
        deleteTarget.value = item;
        return;
    }
    emit('reserve', item);
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
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="az">A–Z</option>
                </select>
            </div>
        </div>

        <div v-if="visibleItems.length" class="wish-grid">
            <article v-for="item in visibleItems" :key="item.id" class="wish-tile">
                <div v-if="hasImage(item)" class="wish-tile-media" @click="emit('open', item, visibleItems)">
                    <img :src="item.image" :alt="displayName(item)" loading="lazy" @error="markBroken(item)" />
                    <span v-if="item.reserved" class="wish-badge on-image">Reserved</span>
                </div>
                <div class="wish-tile-body">
                    <div
                        :class="{ 'wish-open-area': !hasImage(item) }"
                        @click="!hasImage(item) && emit('open', item, visibleItems)"
                    >
                        <span v-if="item.reserved && !hasImage(item)" class="wish-badge" style="margin-bottom: 8px">
                            Reserved
                        </span>
                        <p class="wish-name clamp-2">{{ displayName(item) }}</p>
                        <p v-if="item.notes" class="wish-notes clamp-3">{{ item.notes }}</p>
                        <p v-if="item.owner" class="wish-owner">
                            by {{ item.owner.display }}<span v-if="item.owner.nickname"> “{{ item.owner.nickname }}”</span>
                        </p>
                    </div>
                    <div class="wish-tile-actions">
                        <a
                            v-if="item.link"
                            class="icon-btn"
                            :href="item.link"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="`Open the shop link for ${displayName(item)}`"
                            @click.stop
                        >
                            <ExternalLink :size="19" />
                        </a>
                        <span v-if="item.mine" class="wish-mine-chip">Your wish</span>
                        <button
                            v-else
                            type="button"
                            :class="actionState(item).className"
                            :disabled="actionState(item).disabled"
                            @click="handleAction(item)"
                        >
                            <component :is="actionState(item).icon" :size="17" />
                            <span>{{ actionState(item).label }}</span>
                        </button>
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

.wish-mine-chip {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: 8px 13px;
    border-radius: 999px;
    border: 1px solid var(--card-border);
    background: var(--glass);
    color: var(--text-secondary);
    font-size: 0.8rem;
    font-weight: 700;
}
</style>
