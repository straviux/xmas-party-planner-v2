<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CircleAlert, VenetianMask } from 'lucide-vue-next';
import { post } from '../lib/api';
import { toastError } from '../lib/toast';

// Pick (or change) the guest's secret name: the codename their Secret
// Santa sees at the reveal instead of their real name. Shared by the
// Enter flow (as a step after the PIN) and Guest Home (in a popup).
//
// Emits `saved` with the stored secret name once the server accepts it.
const props = defineProps({
    initial: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['saved']);

const secretName = ref(props.initial || '');
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    const value = secretName.value.trim();
    if (value.length < 2 || value.length > 30) {
        error.value = 'Your secret name needs to be between 2 and 30 characters.';
        return;
    }

    busy.value = true;
    try {
        const body = await post('guest_set_secret_name', { secret_name: value });
        emit('saved', body.secret_name || value);
    } catch (err) {
        if (err.code === 'secret_name_taken') {
            error.value = 'That secret name is already taken. Try another one.';
        } else if (err.code === 'secret_name_invalid') {
            error.value = err.message || 'That secret name cannot be used. Try another one.';
        } else if (err.code === 'not_claimed') {
            toastError('Your session has ended. Enter your name and PIN to continue.');
            router.visit('/enter');
        } else {
            error.value = err.message || 'Your secret name could not be saved. Please try again.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <p class="muted" style="margin: 0 0 18px; line-height: 1.55">
        This is the only name your Secret Santa will see. Your real name stays
        hidden until the gift exchange.
    </p>

    <p v-if="error" class="error-text" style="margin-bottom: 14px">
        <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
        <span>{{ error }}</span>
    </p>

    <form @submit.prevent="submit">
        <label class="field">
            <span class="field-label">Your secret name</span>
            <input
                v-model="secretName"
                class="input"
                type="text"
                autocomplete="off"
                maxlength="40"
                placeholder="For example: Frosty Fox"
                :disabled="busy"
            />
        </label>
        <button type="submit" class="btn btn-primary btn-block" :disabled="busy">
            <VenetianMask :size="18" />
            <span>{{ busy ? 'Saving...' : 'Save my secret name' }}</span>
        </button>
    </form>
</template>
