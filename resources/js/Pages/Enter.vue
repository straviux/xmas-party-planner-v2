<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Check, CircleAlert, KeyRound } from 'lucide-vue-next';
import GuestLayout from '../Components/GuestLayout.vue';
import Mascot from '../Components/Mascot.vue';
import { post } from '../lib/api';

const props = defineProps({
    code: {
        type: String,
        default: null,
    },
});

const inviteCode = ref('');
const error = ref('');
const checked = ref(false);
const busy = ref(false);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    inviteCode.value = props.code || params.get('code') || '';
});

async function submit() {
    error.value = '';
    checked.value = false;
    busy.value = true;
    try {
        await post('invite_check', { code: inviteCode.value.trim() });
        checked.value = true;
    } catch (err) {
        if (err.code === 'invite_incorrect') {
            error.value =
                'That invite code does not look right. Check the code or link from your host and try again.';
        } else if (err.code === 'not_set_up') {
            error.value = 'No party is set up yet. Your host needs to set up the party first.';
        } else {
            error.value = err.message || 'That code could not be checked. Please try again.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Enter your invite code" />
    <GuestLayout>
        <Mascot message="Psst... have your invite code ready?" />

        <div class="card slide-in" style="margin-top: 26px">
            <span class="section-label">Guest entry</span>
            <h1 class="card-title">Enter your invite code</h1>
            <p class="card-subtitle" style="margin-bottom: 22px">
                Your host shared one invite code for the whole party. Type it here to
                get started.
            </p>

            <p v-if="error" class="error-text">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ error }}</span>
            </p>

            <p v-if="checked" class="success-text">
                <strong>That code works.</strong>
                Finding your name is coming in the very next step of the build, so
                sit tight — you are in the right place.
            </p>

            <form @submit.prevent="submit">
                <label class="field">
                    <span class="field-label">Invite code</span>
                    <input
                        v-model="inviteCode"
                        class="input mono"
                        type="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        placeholder="For example: PARTY2026"
                        required
                    />
                </label>
                <button type="submit" class="btn btn-primary btn-block" :disabled="busy">
                    <KeyRound :size="18" />
                    <span>{{ busy ? 'Checking...' : 'Check code' }}</span>
                </button>
            </form>

            <div style="text-align: center; margin-top: 6px">
                <Link href="/" class="host-signin-link">
                    <ArrowLeft :size="13" style="vertical-align: -2px" />
                    Back to the start
                </Link>
            </div>
        </div>

        <!-- Slice 1 bridge: name search, PIN, and reveal replace this page in Slice 2. -->
        <p class="muted small" style="text-align: center; margin-top: 18px">
            <Check :size="14" style="vertical-align: -2px" />
            No account and no password to remember — just your invite code and your name.
        </p>
    </GuestLayout>
</template>
