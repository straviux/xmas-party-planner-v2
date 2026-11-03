<script setup>
import { Check, CircleAlert, X } from 'lucide-vue-next';
import { toastState, dismissToast } from '../lib/toast';
</script>

<template>
    <div class="toast-wrap" aria-live="polite">
        <div
            v-for="item in toastState.items"
            :key="item.id"
            class="toast"
            :class="item.variant"
            role="status"
        >
            <Check v-if="item.variant === 'success'" :size="19" />
            <CircleAlert v-else :size="19" />
            <span style="flex: 1">{{ item.message }}</span>
            <button
                type="button"
                class="icon-btn"
                style="width: 36px; height: 36px; border: none; background: transparent"
                aria-label="Dismiss"
                @click="dismissToast(item.id)"
            >
                <X :size="15" />
            </button>
        </div>
    </div>
</template>
