<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CircleAlert, Dices, VenetianMask } from 'lucide-vue-next';
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

// Random suggestions for guests who do not want to invent a name:
// a festive or funny first word plus a character, thing, or fruit,
// 676 combinations, every one inside the 2 to 30 character rule.
// Purely a suggestion typed into the field: the guest can re-roll,
// edit it, or ignore it, and the save below still runs the server's
// uniqueness check, so a suggestion that happens to be taken just
// gets the taken message.
const NAME_FIRST = [
    'Jolly', 'Frosty', 'Twinkle', 'Cozy', 'Merry', 'Peppy', 'Holly',
    'Ginger', 'Sugar', 'Snowy', 'Tinsel', 'Pudding', 'Waffle',
    'Noodle', 'Mumble', 'Pickle', 'Wobbly', 'Giggly', 'Goofy',
    'Chunky', 'Sleepy', 'Clumsy', 'Cheeky', 'Bouncy', 'Dizzy',
    'Snazzy',
];
const NAME_SECOND = [
    'Reindeer', 'Elf', 'Pixie', 'Fox', 'Penguin', 'Snowman', 'Cookie',
    'Cocoa', 'Candle', 'Mittens', 'Sleigh', 'Star', 'Bell', 'Moose',
    'Panda', 'Sprout', 'Mango', 'Pineapple', 'Coconut', 'Banana',
    'Papaya', 'Cherry', 'Melon', 'Lemon', 'Guava', 'Durian',
];

const lastSuggestion = ref('');

function suggestName() {
    let candidate = '';
    do {
        const first = NAME_FIRST[Math.floor(Math.random() * NAME_FIRST.length)];
        const second = NAME_SECOND[Math.floor(Math.random() * NAME_SECOND.length)];
        candidate = `${first} ${second}`;
    } while (candidate === lastSuggestion.value);
    lastSuggestion.value = candidate;
    secretName.value = candidate;
    error.value = '';
}

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
            error.value = 'That secret name is already taken. Try another one, or tap Suggest one for me.';
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
        <button
            type="button"
            class="btn btn-secondary btn-block"
            :disabled="busy"
            style="margin-bottom: 10px"
            @click="suggestName"
        >
            <Dices :size="18" />
            <span>Suggest one for me</span>
        </button>
        <button type="submit" class="btn btn-primary btn-block" :disabled="busy">
            <VenetianMask :size="18" />
            <span>{{ busy ? 'Saving...' : 'Save my secret name' }}</span>
        </button>
    </form>
</template>
