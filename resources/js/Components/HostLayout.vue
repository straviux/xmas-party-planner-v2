<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ChartColumn, LayoutDashboard, LogOut, Settings, Users } from 'lucide-vue-next';
import { post } from '../lib/api';
import { toastError } from '../lib/toast';

const props = defineProps({
    partyName: {
        type: String,
        default: '',
    },
    username: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const page = usePage();
const fetchedPartyName = ref('');
const fetchedUsername = ref('');

const displayPartyName = computed(() => props.partyName || fetchedPartyName.value || 'No party yet');
const displayUsername = computed(() => props.username || fetchedUsername.value || 'host');

const current = computed(() => {
    const url = page.url || '';
    if (url.startsWith('/host/guests')) return 'guests';
    if (url.startsWith('/host/polls')) return 'polls';
    if (url.startsWith('/host/settings')) return 'settings';
    return 'dashboard';
});

onMounted(async () => {
    if (props.partyName && props.username) return;
    try {
        const status = await post('host_status');
        if (status.exists && status.party) {
            fetchedPartyName.value = status.party.name || '';
        }
        if (status.username) {
            fetchedUsername.value = status.username;
        }
    } catch {
        // The page using this layout fetches its own status and surfaces errors.
    }
});

async function signOut() {
    try {
        await post('host_logout');
    } catch {
        // Signing out locally regardless; the session cookie is replaced on next load.
    }
    window.location.href = '/';
}
</script>

<template>
    <div class="host-shell host-scope">
        <aside class="host-sidebar">
            <div class="host-brand">
                <span class="section-label" style="color: var(--host-teal)">Admin</span>
                <div class="host-brand-name">Host</div>
                <div class="host-brand-party">{{ displayPartyName }}</div>
            </div>

            <nav class="host-nav" aria-label="Host navigation">
                <Link href="/host" class="host-nav-item" :class="{ active: current === 'dashboard' }">
                    <LayoutDashboard :size="19" />
                    <span>Dashboard</span>
                </Link>
                <Link href="/host/guests" class="host-nav-item" :class="{ active: current === 'guests' }">
                    <Users :size="19" />
                    <span>Guests</span>
                </Link>
                <Link href="/host/polls" class="host-nav-item" :class="{ active: current === 'polls' }">
                    <ChartColumn :size="19" />
                    <span>Polls</span>
                </Link>
                <Link href="/host/settings" class="host-nav-item" :class="{ active: current === 'settings' }">
                    <Settings :size="19" />
                    <span>Party settings</span>
                </Link>
            </nav>

            <div class="host-sidebar-bottom">
                <div class="host-signed-in">
                    Signed in as <strong>{{ displayUsername }}</strong>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start" @click="signOut">
                    <LogOut :size="16" />
                    <span>Sign out</span>
                </button>
                <Link href="/" class="host-nav-item" style="padding-left: 4px">
                    <ArrowLeft :size="17" />
                    <span>Back to guest home</span>
                </Link>
            </div>
        </aside>

        <div class="host-main-scroll">
            <main class="host-content">
                <header v-if="title" class="host-header">
                    <div>
                        <h1 class="host-title">{{ title }}</h1>
                        <p v-if="subtitle" class="host-subtitle">{{ subtitle }}</p>
                    </div>
                    <div class="row-actions">
                        <slot name="actions" />
                    </div>
                </header>
                <slot />
            </main>
        </div>
    </div>
</template>
