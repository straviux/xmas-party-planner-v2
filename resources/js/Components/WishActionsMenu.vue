<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { Pencil, Settings, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['edit', 'delete']);

// The menu is teleported to <body> and positioned with fixed coordinates
// taken from the button's bounding rect: grid tiles and the quick-view
// card both clip overflow, so a dropdown rendered in place would be cut
// off. It opens below the button, flipping above it when the space below
// is too small, and closes on any scroll so it never detaches visually.
const MENU_WIDTH = 190;
const MENU_HEIGHT = 108;

const open = ref(false);
const buttonEl = ref(null);
const menuEl = ref(null);
const menuStyle = ref({});

function positionMenu() {
    const rect = buttonEl.value?.getBoundingClientRect();
    if (!rect) return;
    let top = rect.bottom + 6;
    if (top + MENU_HEIGHT > window.innerHeight - 8) {
        top = rect.top - MENU_HEIGHT - 6;
    }
    top = Math.max(8, top);
    const left = Math.max(
        8,
        Math.min(rect.right - MENU_WIDTH, window.innerWidth - MENU_WIDTH - 8),
    );
    menuStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
        width: `${MENU_WIDTH}px`,
    };
}

function addListeners() {
    document.addEventListener('click', onDocumentClick, true);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
}

function removeListeners() {
    document.removeEventListener('click', onDocumentClick, true);
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', closeMenu);
    window.removeEventListener('scroll', closeMenu, true);
}

async function toggleMenu() {
    if (props.busy) return;
    if (open.value) {
        closeMenu();
        return;
    }
    open.value = true;
    await nextTick();
    positionMenu();
    addListeners();
}

function closeMenu() {
    if (!open.value) return;
    open.value = false;
    removeListeners();
}

function onDocumentClick(event) {
    if (buttonEl.value?.contains(event.target)) return;
    if (menuEl.value?.contains(event.target)) return;
    closeMenu();
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        closeMenu();
        buttonEl.value?.focus();
    }
}

function choose(action) {
    closeMenu();
    emit(action, props.item);
}

onBeforeUnmount(removeListeners);
</script>

<template>
    <button
        ref="buttonEl"
        type="button"
        class="icon-btn"
        :disabled="busy"
        aria-label="Wish settings"
        aria-haspopup="menu"
        :aria-expanded="open"
        @click.stop="toggleMenu"
    >
        <Settings :size="19" />
    </button>
    <Teleport to="body">
        <div
            v-if="open"
            ref="menuEl"
            class="wish-actions-menu pop-in"
            :style="menuStyle"
            role="menu"
            aria-label="Wish settings"
        >
            <button type="button" class="wish-actions-item" role="menuitem" @click="choose('edit')">
                <Pencil :size="16" />
                <span>Edit wish</span>
            </button>
            <button
                type="button"
                class="wish-actions-item wish-actions-danger"
                role="menuitem"
                @click="choose('delete')"
            >
                <Trash2 :size="16" />
                <span>Delete wish</span>
            </button>
        </div>
    </Teleport>
</template>

<style scoped>
.wish-actions-menu {
    position: fixed;
    z-index: 180;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 6px;
    background: #262a49;
    border: 1px solid var(--card-border);
    border-radius: 14px;
    box-shadow: 0 18px 44px rgba(5, 4, 22, 0.55);
}

.wish-actions-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--text-primary);
    font: inherit;
    font-size: 0.92rem;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
}

.wish-actions-item:hover {
    background: var(--glass);
}

.wish-actions-danger {
    color: #fca5a5;
}

.wish-actions-danger:hover {
    background: var(--danger-tint);
}
</style>
