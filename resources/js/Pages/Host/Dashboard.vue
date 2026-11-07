<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import {
    Calendar,
    CircleAlert,
    Copy,
    Eraser,
    KeyRound,
    PartyPopper,
    Printer,
    RefreshCw,
    RotateCcw,
    Settings,
    ShieldAlert,
    Trash2,
    Users,
    Wallet,
} from 'lucide-vue-next';
import ConfirmModal from '../../Components/ConfirmModal.vue';
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

/* One shared ConfirmModal drives every confirm on this page. confirmState
   holds { key, title, body, confirmLabel, cancelLabel?, danger, run } and
   actionBusy holds the key of the action currently in flight. */
const confirmState = ref(null);
const actionBusy = ref(null);

function askConfirm(config) {
    confirmState.value = config;
}

function closeConfirm() {
    if (!actionBusy.value) {
        confirmState.value = null;
    }
}

async function runConfirm() {
    const config = confirmState.value;
    if (!config || actionBusy.value) return;
    actionBusy.value = config.key;
    try {
        await config.run();
    } finally {
        actionBusy.value = null;
        confirmState.value = null;
    }
}

const party = computed(() => (status.value && status.value.exists ? status.value.party : null));
const guests = computed(() => (status.value && status.value.guests) || []);
const counts = computed(
    () => (status.value && status.value.counts) || { guests: 0, locked: 0, awaiting: 0, polls: 0, secret_names_set: 0 },
);
const lockedPercent = computed(() => {
    if (!counts.value.guests) return 0;
    return Math.round((counts.value.locked / counts.value.guests) * 100);
});
const secretPercent = computed(() => {
    if (!counts.value.guests) return 0;
    return Math.round(((counts.value.secret_names_set || 0) / counts.value.guests) * 100);
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

function onKeydown(event) {
    if (event.key === 'Escape' && pwOpen.value && !pwBusy.value && !confirmState.value) {
        closePasswordModal();
    }
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});

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
    try {
        const result = await post('host_invite_regen');
        if (status.value && status.value.party) {
            status.value.party.invite_code =
                (result.party && result.party.invite_code) || result.invite_code || status.value.party.invite_code;
        }
        toastSuccess('New invite code created. The old link no longer works.');
        await load();
    } catch (err) {
        toastError(err.message || 'The invite code could not be changed.');
    }
}

function askRegen() {
    askConfirm({
        key: 'regen',
        title: 'Make a new invite code?',
        body: 'The old invite link and QR code will stop working. Your guests, their locked names, wishlists, and votes do not change.',
        confirmLabel: 'Yes, make a new code',
        cancelLabel: 'Keep current code',
        run: regenInvite,
    });
}

function askResetOne(guest) {
    askConfirm({
        key: `reset-${guest.id}`,
        title: `Reset ${guest.display}?`,
        body: `This clears the claim and the reveal for ${guest.display}, so they can enter and reveal again. It does not clear their PIN, their wishlist, or their votes.`,
        confirmLabel: 'Yes, reset them',
        run: async () => {
            try {
                await post('reset_one', { guest_id: guest.id });
                toastSuccess(`${guest.display} has been reset`);
                await load();
            } catch (err) {
                toastError(err.message || 'That reset could not be done.');
            }
        },
    });
}

function askUnclaimAll() {
    askConfirm({
        key: 'unclaim',
        title: 'Unclaim all names?',
        body: 'This clears every claim and reveal, so all guests can enter and reveal again. It does not change anyone\'s recipient, does not clear PINs, and does not delete guests, wishlists, polls, or votes.',
        confirmLabel: 'Yes, unclaim all',
        run: async () => {
            try {
                const result = await post('host_unclaim_all');
                toastSuccess(
                    typeof result.unclaimed === 'number'
                        ? `All names unclaimed (${result.unclaimed} were locked)`
                        : 'All names unclaimed',
                );
                await load();
            } catch (err) {
                toastError(err.message || 'Names could not be unclaimed.');
            }
        },
    });
}

function askClearWishlists() {
    askConfirm({
        key: 'clear-wishlists',
        title: 'Clear all wishlists?',
        body: 'This deletes every wishlist item for this party. Guests, polls, claims, and PINs stay exactly as they are.',
        confirmLabel: 'Yes, clear wishlists',
        danger: true,
        run: async () => {
            try {
                const result = await post('host_clear_wishlists');
                toastSuccess(
                    typeof result.deleted === 'number'
                        ? `Wishlists cleared (${result.deleted} items deleted)`
                        : 'All wishlists cleared',
                );
                await load();
            } catch (err) {
                toastError(err.message || 'Wishlists could not be cleared.');
            }
        },
    });
}

function askResetAll() {
    askConfirm({
        key: 'reset-all',
        title: 'Delete the whole party?',
        body: 'This deletes the entire party: every guest, wishlist item, poll, option, and vote goes with it. Claims, PINs, and the invite code are gone too. This cannot be undone.',
        confirmLabel: 'Delete the party',
        danger: true,
        run: async () => {
            try {
                await post('reset_all');
                toastSuccess('The party has been deleted');
                await load();
            } catch (err) {
                toastError(err.message || 'The party could not be deleted.');
            }
        },
    });
}

/* ---------- Change password ---------- */

const pwForm = ref({ current: '', next: '', confirm: '' });
const pwError = ref('');
const pwBusy = ref(false);
const pwOpen = ref(false);
const pwCurrentInput = ref(null);

const pwMessages = {
    password_incorrect: 'Your current password is not right. Check it and try again.',
    password_too_short: 'That new password is too short. Pick a longer one.',
    password_mismatch: 'The two new passwords do not match.',
    password_same: 'The new password must be different from your current password.',
};

async function openPasswordModal() {
    pwError.value = '';
    pwOpen.value = true;
    await nextTick();
    pwCurrentInput.value?.focus();
}

function closePasswordModal() {
    if (pwBusy.value) return;
    pwOpen.value = false;
    pwError.value = '';
    pwForm.value = { current: '', next: '', confirm: '' };
}

async function changePassword() {
    pwError.value = '';
    if (pwForm.value.next !== pwForm.value.confirm) {
        pwError.value = pwMessages.password_mismatch;
        return;
    }
    pwBusy.value = true;
    try {
        await post('host_change_password', {
            current_password: pwForm.value.current,
            new_password: pwForm.value.next,
            new_password_confirmation: pwForm.value.confirm,
        });
        pwForm.value = { current: '', next: '', confirm: '' };
        pwOpen.value = false;
        toastSuccess('Password changed');
    } catch (err) {
        pwError.value = pwMessages[err.code] || err.message || 'The password could not be changed.';
    } finally {
        pwBusy.value = false;
    }
}

async function printQr() {
    if (!party.value) return;
    const link = `${window.location.origin}/join/${party.value.invite_code}`;
    try {
        const svg = await QRCode.toString(link, {
            type: 'svg',
            margin: 4,
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
            .qr{width:340px;margin:22px auto}.qr svg{width:100%;height:auto;display:block}.code{font-family:monospace;font-size:18px;letter-spacing:2px}</style>
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
                Set up your party first: name, date, budget, and your guest list.
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
                <p class="muted small" style="margin: 14px 0 10px">
                    Secret names: {{ counts.secret_names_set || 0 }} of {{ counts.guests }} guests
                    have picked one ({{ secretPercent }}%).
                </p>
                <div class="progress-track">
                    <div class="progress-fill" :style="{ width: `${secretPercent}%` }"></div>
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
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!!actionBusy" @click="askRegen">
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
                                    :disabled="!guest.claimed || !!actionBusy"
                                    :title="
                                        guest.claimed
                                            ? `Reset ${guest.display} so they can enter and reveal again`
                                            : 'Only a locked name can be reset'
                                    "
                                    @click="askResetOne(guest)"
                                >
                                    <RotateCcw :size="14" />
                                    <span>Reset</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted" style="margin: 10px 0 2px">
                    No guests yet. Add your guest list in Party settings or on the Guests page.
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

        <template v-if="!loading && !loadError">
            <section class="host-panel">
                <h2 class="host-panel-title">Admin controls</h2>

                <div class="admin-block">
                    <h3 class="admin-block-title">
                        <KeyRound :size="16" />
                        <span>Change password</span>
                    </h3>
                    <p class="admin-row-sub" style="margin-bottom: 14px">
                        Pick a new password for your host sign-in. You stay signed in here.
                    </p>
                    <button type="button" class="btn btn-teal btn-sm" @click="openPasswordModal">
                        <KeyRound :size="15" />
                        <span>Change password</span>
                    </button>
                </div>
            </section>

            <section v-if="party" class="host-panel danger-zone">
                <h2 class="host-panel-title danger-zone-title">
                    <ShieldAlert :size="19" />
                    <span>Danger zone</span>
                </h2>

                <div class="admin-row">
                    <div class="admin-row-text">
                        <p class="admin-row-title">Unclaim all names</p>
                        <p class="admin-row-sub">
                            Clears every claim and reveal, so all guests can enter and reveal again.
                            It does not redraw recipients, does not clear PINs, and does not delete
                            guests, wishlists, or polls. This is the step to do before a Redraw.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-danger-ghost btn-sm"
                        :disabled="!!actionBusy || counts.locked === 0"
                        :title="counts.locked === 0 ? 'No names are locked right now' : 'Clear every claim and reveal'"
                        @click="askUnclaimAll"
                    >
                        <Users :size="15" />
                        <span>Unclaim all</span>
                    </button>
                </div>

                <div class="admin-row">
                    <div class="admin-row-text">
                        <p class="admin-row-title">Clear wishlists</p>
                        <p class="admin-row-sub">
                            Deletes every wishlist item for this party. Guests, polls, and claims stay.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-danger-ghost btn-sm"
                        :disabled="!!actionBusy"
                        @click="askClearWishlists"
                    >
                        <Eraser :size="15" />
                        <span>Clear wishlists</span>
                    </button>
                </div>

                <div class="admin-row">
                    <div class="admin-row-text">
                        <p class="admin-row-title">Reset everything</p>
                        <p class="admin-row-sub">
                            Deletes the whole party: guests, wishlists, polls, and votes.
                            This cannot be undone.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-danger-ghost btn-sm"
                        :disabled="!!actionBusy"
                        @click="askResetAll"
                    >
                        <Trash2 :size="15" />
                        <span>Reset everything</span>
                    </button>
                </div>
            </section>
        </template>

        <div v-if="pwOpen" class="modal-backdrop" @click.self="closePasswordModal">
            <div
                class="modal-card pop-in"
                style="width: min(94vw, 620px)"
                role="dialog"
                aria-modal="true"
                aria-label="Change password"
            >
                <h3 class="modal-title">Change password</h3>
                <p class="muted small" style="margin: 0 0 6px">
                    Pick a new password for your host sign-in. You stay signed in here.
                </p>
                <p v-if="pwError" class="error-text" style="margin-top: 12px">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ pwError }}</span>
                </p>
                <form style="margin-top: 14px" @submit.prevent="changePassword">
                    <div class="form-grid">
                        <label class="field">
                            <span class="field-label">Current password</span>
                            <input
                                ref="pwCurrentInput"
                                v-model="pwForm.current"
                                class="input"
                                type="password"
                                autocomplete="current-password"
                                required
                            />
                        </label>
                        <span class="field" aria-hidden="true"></span>
                        <label class="field">
                            <span class="field-label">New password</span>
                            <input
                                v-model="pwForm.next"
                                class="input"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                        </label>
                        <label class="field">
                            <span class="field-label">Confirm new password</span>
                            <input
                                v-model="pwForm.confirm"
                                class="input"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="pwBusy" @click="closePasswordModal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-teal btn-sm" :disabled="pwBusy">
                            <KeyRound :size="15" />
                            <span>{{ pwBusy ? 'Changing...' : 'Change password' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            v-if="confirmState"
            :title="confirmState.title"
            :body="confirmState.body"
            :confirm-label="confirmState.confirmLabel"
            :cancel-label="confirmState.cancelLabel || 'Cancel'"
            :danger="!!confirmState.danger"
            :busy="!!actionBusy"
            @confirm="runConfirm"
            @cancel="closeConfirm"
        />
    </HostLayout>
</template>
