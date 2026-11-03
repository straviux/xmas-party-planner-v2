<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import {
    Calendar,
    CircleAlert,
    Copy,
    PartyPopper,
    Printer,
    RefreshCw,
    Settings,
    Users,
    Wallet,
} from 'lucide-vue-next';
import HostLayout from '../../Components/HostLayout.vue';
import SignInGate from './SignInGate.vue';
import { post } from '../../lib/api';
import { toastError, toastSuccess } from '../../lib/toast';

const props = defineProps({
    authed: {
        type: Boolean,
        default: false,
    },
    username: {
        type: String,
        default: '',
    },
});

const loading = ref(true);
const loadError = ref('');
const status = ref(null);
const confirmRegen = ref(false);
const busy = ref(false);

const party = computed(() => (status.value && status.value.exists ? status.value.party : null));
const guests = computed(() => (status.value && status.value.guests) || []);
const counts = computed(
    () => (status.value && status.value.counts) || { guests: 0, locked: 0, awaiting: 0, polls: 0 },
);
const lockedPercent = computed(() => {
    if (!counts.value.guests) return 0;
    return Math.round((counts.value.locked / counts.value.guests) * 100);
});

function guestStatus(guest) {
    if (guest.revealed) return { label: 'Revealed', cls: 'badge-revealed' };
    if (guest.claimed) return { label: 'Locked', cls: 'badge-locked' };
    return { label: 'Awaiting', cls: 'badge-awaiting' };
}

function formatDate(value) {
    if (!value) return 'No date set';
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        status.value = await post('host_status');
    } catch (err) {
        loadError.value =
            err.code === 'forbidden'
                ? 'You are signed out. Sign in again to see the dashboard.'
                : err.message || 'The dashboard could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function copyInvite() {
    if (!party.value) return;
    const link = `${window.location.origin}/join/${party.value.invite_code}`;
    try {
        await navigator.clipboard.writeText(link);
        toastSuccess('Invite link copied.');
    } catch {
        try {
            await navigator.clipboard.writeText(party.value.invite_code);
            toastSuccess('Invite code copied.');
        } catch {
            toastError('Copy did not work here. Long-press the code to copy it by hand.');
        }
    }
}

async function regenInvite() {
    busy.value = true;
    try {
        const result = await post('host_invite_regen');
        if (status.value && status.value.party) {
            status.value.party.invite_code =
                (result.party && result.party.invite_code) || result.invite_code || status.value.party.invite_code;
        }
        confirmRegen.value = false;
        toastSuccess('New invite code created. The old link no longer works.');
        await load();
    } catch (err) {
        toastError(err.message || 'The invite code could not be changed.');
    } finally {
        busy.value = false;
    }
}

async function printQr() {
    if (!party.value) return;
    const link = `${window.location.origin}/join/${party.value.invite_code}`;
    try {
        const svg = await QRCode.toString(link, {
            type: 'svg',
            margin: 2,
            width: 420,
            color: { dark: '#1c1b33', light: '#ffffff' },
        });
        const win = window.open('', '_blank', 'width=560,height=720');
        if (!win) {
            toastError('Pop-ups are blocked, so the print sheet could not open.');
            return;
        }
        const name = String(party.value.name || 'Christmas Party').replace(/</g, '');
        win.document.write(`<!DOCTYPE html><html><head><title>Party invite QR</title>
            <style>body{font-family:Arial,sans-serif;text-align:center;padding:36px;color:#1c1b33}
            h1{font-size:26px;margin:0 0 6px}p{color:#444;line-height:1.5}
            .qr{width:340px;height:340px;margin:22px auto}.code{font-family:monospace;font-size:18px;letter-spacing:2px}</style>
            </head><body><h1>${name}</h1>
            <p>Scan this code with your phone camera to join the party.</p>
            <div class="qr">${svg}</div>
            <p>Or open this link:<br><strong>${link}</strong></p>
            <p class="code">${party.value.invite_code}</p>
            <script>window.onload=function(){setTimeout(function(){window.print()},250)}<\/script>
            </body></html>`);
        win.document.close();
    } catch {
        toastError('The QR code could not be made. You can still share the invite link.');
    }
}
</script>

<template>
    <Head title="Host dashboard" />
    <SignInGate v-if="!authed" />
    <HostLayout
        v-else
        :party-name="party ? party.name : ''"
        :username="props.username"
        title="Dashboard"
        subtitle="Your party at a glance. Names and lock status only — who got whom stays secret, even here."
    >
        <template #actions>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="load">
                <RefreshCw :size="16" />
                <span>Refresh</span>
            </button>
        </template>

        <p v-if="loadError" class="error-text">
            <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
            <span>{{ loadError }}</span>
        </p>

        <p v-if="loading" class="muted">Loading your party...</p>

        <!-- No party yet: a normal empty state, not an error. -->
        <section v-else-if="!party" class="host-panel empty-state">
            <PartyPopper :size="40" />
            <h2 class="empty-state-title">No party yet</h2>
            <p class="empty-state-text">
                Set up your party first — name, date, budget, and your guest list.
                Everything else starts from there.
            </p>
            <Link href="/host/settings" class="btn btn-teal">
                <Settings :size="17" />
                <span>Set up your party</span>
            </Link>
        </section>

        <template v-else>
            <div class="stat-grid">
                <div class="stat-card">
                    <span class="section-label">Guests</span>
                    <div class="stat-value">{{ counts.guests }}</div>
                </div>
                <div class="stat-card">
                    <span class="section-label">Locked</span>
                    <div class="stat-value">{{ counts.locked }}</div>
                </div>
                <div class="stat-card">
                    <span class="section-label">Awaiting</span>
                    <div class="stat-value">{{ counts.awaiting }}</div>
                </div>
                <div class="stat-card">
                    <span class="section-label">Polls</span>
                    <div class="stat-value">{{ counts.polls }}</div>
                </div>
            </div>

            <section class="host-panel">
                <span class="section-label">Names locked in</span>
                <p class="muted small" style="margin: 0 0 10px">
                    {{ counts.locked }} of {{ counts.guests }} guests have locked their name
                    ({{ lockedPercent }}%).
                </p>
                <div class="progress-track">
                    <div class="progress-fill" :style="{ width: `${lockedPercent}%` }"></div>
                </div>
            </section>

            <section class="host-panel">
                <h2 class="host-panel-title">Party</h2>
                <p class="muted" style="margin: 0 0 16px; line-height: 1.7">
                    <strong style="color: #fff">{{ party.name }}</strong><br />
                    <Calendar :size="14" style="vertical-align: -2px" />
                    {{ formatDate(party.date) }}
                    <span v-if="party.budget">
                        &nbsp;·&nbsp;
                        <Wallet :size="14" style="vertical-align: -2px" />
                        Gift budget: {{ party.budget }}
                    </span>
                </p>
                <span class="section-label">Invite link</span>
                <p style="margin: 0 0 14px">
                    <span class="mono" style="font-size: 1.02rem">{{ party.invite_code }}</span>
                    <span class="muted small" style="display: block; margin-top: 4px">
                        {{ `${typeof window !== 'undefined' ? window.location.origin : ''}/join/${party.invite_code}` }}
                    </span>
                </p>
                <div class="row-actions">
                    <button type="button" class="btn btn-teal btn-sm" @click="copyInvite">
                        <Copy :size="15" />
                        <span>Copy invite link</span>
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="confirmRegen = true">
                        <RefreshCw :size="15" />
                        <span>Regen code</span>
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="printQr">
                        <Printer :size="15" />
                        <span>QR Print</span>
                    </button>
                </div>
            </section>

            <section class="host-panel">
                <h2 class="host-panel-title">Claimed names</h2>
                <p class="muted small" style="margin: 0 0 8px">
                    Who has locked in — never who they got.
                </p>
                <table v-if="guests.length" class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Nickname</th>
                            <th>Status</th>
                            <th>Reset</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="guest in guests" :key="guest.id">
                            <td>{{ guest.display }}</td>
                            <td>{{ guest.nickname || '—' }}</td>
                            <td>
                                <span class="badge" :class="guestStatus(guest).cls">
                                    {{ guestStatus(guest).label }}
                                </span>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm"
                                    disabled
                                    title="Resetting one guest arrives with the full host admin in a later step"
                                >
                                    Reset
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted" style="margin: 10px 0 2px">
                    No guests yet — add your guest list in Party settings or the Guests page.
                </p>
            </section>

            <section class="host-panel">
                <h2 class="host-panel-title">Quick actions</h2>
                <div class="row-actions" style="margin-top: 12px">
                    <Link href="/host/guests" class="btn btn-secondary btn-sm">
                        <Users :size="15" />
                        <span>Manage guests</span>
                    </Link>
                    <Link href="/host/polls" class="btn btn-secondary btn-sm">
                        <span>View polls</span>
                    </Link>
                    <Link href="/host/settings" class="btn btn-secondary btn-sm">
                        <Settings :size="15" />
                        <span>Party settings</span>
                    </Link>
                </div>
            </section>
        </template>

        <div v-if="confirmRegen" class="modal-backdrop" @click.self="confirmRegen = false">
            <div class="modal-card pop-in" role="dialog" aria-modal="true">
                <h3 class="modal-title">Make a new invite code?</h3>
                <p class="muted" style="margin: 0; line-height: 1.55">
                    The old invite link and QR code will stop working. Your guests,
                    their locked names, wishlists, and votes do not change.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary btn-sm" @click="confirmRegen = false">
                        Keep current code
                    </button>
                    <button type="button" class="btn btn-teal btn-sm" :disabled="busy" @click="regenInvite">
                        {{ busy ? 'Working...' : 'Yes, make a new code' }}
                    </button>
                </div>
            </div>
        </div>
    </HostLayout>
</template>
