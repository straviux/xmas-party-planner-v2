<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CircleAlert, KeyRound } from 'lucide-vue-next';
import { post } from '../../lib/api';

const username = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    busy.value = true;
    try {
        await post('host_login', {
            username: username.value.trim(),
            password: password.value,
        });
        window.location.reload();
    } catch (err) {
        if (err.code === 'invalid_credentials') {
            error.value = 'That username or password does not match. Check both and try again.';
        } else {
            error.value = err.message || 'Sign-in did not work. Please try again.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="guest-shell" scroll-region>
        <main class="guest-container" style="padding-top: 56px">
            <div class="card slide-in">
                <span class="section-label">Host only</span>
                <h1 class="card-title">Sign in to continue</h1>
                <p class="card-subtitle" style="margin-bottom: 22px">
                    This part of the app is for the party host. Sign in with your host
                    username and password to manage the party.
                </p>

                <p v-if="error" class="error-text">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ error }}</span>
                </p>

                <form @submit.prevent="submit">
                    <label class="field">
                        <span class="field-label">Username</span>
                        <input
                            v-model="username"
                            class="input"
                            type="text"
                            autocomplete="username"
                            required
                            placeholder="Your host username"
                        />
                    </label>
                    <label class="field">
                        <span class="field-label">Password</span>
                        <input
                            v-model="password"
                            class="input"
                            type="password"
                            autocomplete="current-password"
                            required
                            placeholder="Your password"
                        />
                    </label>
                    <button type="submit" class="btn btn-primary btn-block" :disabled="busy">
                        <KeyRound :size="18" />
                        <span>{{ busy ? 'Signing in...' : 'Sign in' }}</span>
                    </button>
                </form>

                <div style="text-align: center">
                    <Link href="/" class="host-signin-link">Back to the party home page</Link>
                </div>
            </div>
        </main>
    </div>
</template>
