<script setup>
import { computed, ref } from 'vue';
import { Plus, Search } from 'lucide-vue-next';
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
    // My wishes only: open the grid with an add-a-wish card.
    addCard: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['add', 'delete', 'edit', 'open']);

const query = ref('');
const broken = ref({});
const deleteTarget = ref(null);


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
    // Items arrive newest first from the server and keep that order;
    // the only filtering left is the search box (owner's call,
    // 2026-11-09: the filter chips and sort dropdown are gone).
    const q = normalizeText(query.value);
    if (!q) return props.items;

    return props.items.filter((item) => {
        const parts = [item.text, item.notes, item.link];
        // Combined views attach the wisher; searching their secret
        // name finds their wishes too. Absent elsewhere, so inert.
        if (item.owner) parts.push(item.owner.secret_name);
        const haystack = normalizeText(parts.filter(Boolean).join(' '));
        return haystack.includes(q);
    });
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

        <div v-if="visibleItems.length || props.addCard" class="wish-grid">
            <button
                v-if="props.addCard"
                type="button"
                class="wish-tile wish-add-card"
                @click="emit('add')"
            >
                <span class="wish-add-icon"><Plus :size="26" /></span>
                <span class="wish-add-label">Add a wish</span>
                <span class="wish-add-sub">Post up to three wishes</span>
            </button>
            <article v-for="item in visibleItems" :key="item.id" class="wish-tile">
                <WishActionsMenu
                    v-if="props.mode === 'mine'"
                    button-class="wish-tile-settings"
                    :item="item"
                    :busy="props.busyId === item.id"
                    @edit="emit('edit', $event)"
                    @delete="handleAction"
                />
                <div v-if="hasImage(item)" class="wish-tile-media" @click="emit('open', item, visibleItems)">
                    <img :src="item.image" :alt="displayName(item)" loading="lazy" @error="markBroken(item)" />
                    <div class="wish-photo-foot" :class="{ 'has-chip': item.mine }">
                        <p class="wish-name clamp-2">{{ displayName(item) }}</p>
                        <span v-if="item.mine" class="wish-mine-chip wish-mine-on-photo">Me</span>
                    </div>
                </div>
                <div class="wish-tile-body">
                    <div
                        :class="{ 'wish-open-area': !hasImage(item) }"
                        @click="!hasImage(item) && emit('open', item, visibleItems)"
                    >
                        <p v-if="!hasImage(item)" class="wish-name clamp-2">{{ displayName(item) }}</p>
                        <p v-if="item.owner" class="wish-owner">
                            by <template v-if="item.owner.secret_name">{{ item.owner.secret_name }}</template><span
                                v-else
                                class="wish-owner-placeholder"
                                role="img"
                                aria-label="Secret name not picked yet"
                            >???</span>
                        </p>
                    </div>
                    <div v-if="item.mine && !hasImage(item)" class="wish-tile-actions">
                        <span class="wish-mine-chip">Me</span>
                    </div>
                </div>
            </article>
        </div>

        <p v-else-if="!props.addCard" class="muted" style="margin: 6px 0 0">
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
