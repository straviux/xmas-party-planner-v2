<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, CircleAlert, KeyRound, QrCode, Search, UserRound } from 'lucide-vue-next';
import GuestLayout from '../Components/GuestLayout.vue';
import Mascot from '../Components/Mascot.vue';
import QrScanner from '../Components/QrScanner.vue';
import SecretNameForm from '../Components/SecretNameForm.vue';
import { post } from '../lib/api';
import { toastError } from '../lib/toast';

const props = defineProps({
    code: {
        type: String,
        default: null,
    },
});

// Steps: invite -> search -> confirm -> pin -> secret (only when the
// guest has no secret name yet; guests who picked one skip it entirely)
const step = ref('invite');
const busy = ref(false);
// Where the flow continues once the secret name step is done.
const pendingDestination = ref('/reveal');

// Invite
const inviteCode = ref('');
const inviteError = ref('');

// Search
const party = ref(null);
const guests = ref([]);
const listLoaded = ref(false);
const query = ref('');
const selected = ref(null);

// PIN
const pin = ref('');
const pinConfirmation = ref('');
const pinError = ref('');

/* ---------- fuzzy name matching (mirrors the backend rules) ---------- */

function normalize(value) {
    return (value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

function levenshtein(a, b) {
    const rows = a.length + 1;
    const cols = b.length + 1;
    const grid = Array.from({ length: rows }, () => new Array(cols).fill(0));
    for (let i = 0; i < rows; i++) grid[i][0] = i;
    for (let j = 0; j < cols; j++) grid[0][j] = j;
    for (let i = 1; i < rows; i++) {
        for (let j = 1; j < cols; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            grid[i][j] = Math.min(grid[i - 1][j] + 1, grid[i][j - 1] + 1, grid[i - 1][j - 1] + cost);
        }
    }
    return grid[a.length][b.length];
}

function guestMatches(guest, rawQuery) {
    const q = normalize(rawQuery);
    if (!q) return true;
    const fields = [guest.display, guest.nickname, ...(guest.aliases || [])]
        .map(normalize)
        .filter(Boolean);
    if (fields.some((field) => field.includes(q))) return true;
    // One-letter typo tolerance against name tokens of 4+ letters.
    const queryTokens = q.split(' ').filter((token) => token.length >= 4);
    const nameTokens = fields.flatMap((field) => field.split(' ')).filter((token) => token.length >= 4);
    return queryTokens.some((qt) => nameTokens.some((nt) => levenshtein(qt, nt) <= 1));
}

// Names are only listed once the search has at least 2 letters;
// an empty or 1-letter search shows a hint instead of the whole list.
const queryReady = computed(() => normalize(query.value).length >= 2);
const results = computed(() =>
    queryReady.value ? guests.value.filter((guest) => guestMatches(guest, query.value)) : [],
);

/* ---------- flow ---------- */

async function loadList() {
    try {
        const body = await post('list');
        party.value = body.party || null;
        guests.value = body.guests || [];
    } catch (err) {
        toastError(err.message || 'The guest list could not be loaded. Please try again.');
        party.value = null;
        guests.value = [];
    } finally {
        listLoaded.value = true;
    }
}

async function checkInvite(code) {
    await post('invite_check', { code });
    await loadList();
    step.value = 'search';
}

// One message mapping for every way a code arrives (typed, scanned, or
// from a /join link), so a bad code always lands on the invite form with
// the same plain-language error instead of a silent dead state.
function inviteErrorFor(err) {
    if (err && err.code === 'invite_incorrect') {
        return 'That invite code does not match this party. Check the code and try again.';
    }
    if (err && err.code === 'not_set_up') {
        return 'No party set up yet. The host needs to set it up first.';
    }
    return (err && err.message) || 'That code could not be checked. Please try again.';
}

async function runInviteCheck(code) {
    inviteError.value = '';
    busy.value = true;
    try {
        await checkInvite(code);
        return true;
    } catch (err) {
        inviteError.value = inviteErrorFor(err);
        step.value = 'invite';
        return false;
    } finally {
        busy.value = false;
    }
}

async function submitInvite() {
    await runInviteCheck(inviteCode.value.trim());
}

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    const code = props.code || params.get('code') || '';
    if (code) {
        inviteCode.value = code;
        // On failure runInviteCheck leaves the guest on the invite form
        // with the code filled in and the error showing.
        await runInviteCheck(code.trim());
    }
});

/* ---------- QR scanner ---------- */

const scannerOpen = ref(false);

// The scanner hands over the extracted code; run it through the exact
// same invite check as a typed code, right here, no navigation.
async function onScanned(code) {
    scannerOpen.value = false;
    inviteCode.value = code;
    await runInviteCheck(code);
}

function pick(guest) {
    selected.value = guest;
    pinError.value = '';
    step.value = 'confirm';
}

function backToSearch() {
    selected.value = null;
    pin.value = '';
    pinConfirmation.value = '';
    pinError.value = '';
    step.value = 'search';
}

function confirmIdentity() {
    pin.value = '';
    pinConfirmation.value = '';
    pinError.value = '';
    step.value = 'pin';
}

function digitsOnly(value) {
    return (value || '').replace(/\D/g, '').slice(0, 4);
}

const PIN_MESSAGES = {
    pin_incorrect: 'That PIN is not right. Try again.',
    pin_must_be_4_digits: 'Your PIN must be exactly 4 digits.',
    pin_mismatch: 'The two PINs do not match.',
    pin_required_create: 'Create a 4-digit PIN to continue.',
    pin_already_set: 'That PIN is not right. Try again.',
    not_set_up: 'No party set up yet. The host needs to set it up first.',
    guest_not_found: 'That name is not on the guest list anymore. Go back and pick your name again.',
};

async function submitPin() {
    pinError.value = '';
    const createMode = selected.value && selected.value.needs_pin;
    const enteredPin = digitsOnly(pin.value);
    const enteredConfirmation = digitsOnly(pinConfirmation.value);

    if (enteredPin.length !== 4) {
        pinError.value = PIN_MESSAGES.pin_must_be_4_digits;
        return;
    }
    if (createMode && enteredConfirmation.length !== 4) {
        pinError.value = PIN_MESSAGES.pin_must_be_4_digits;
        return;
    }
    if (createMode && enteredPin !== enteredConfirmation) {
        pinError.value = PIN_MESSAGES.pin_mismatch;
        return;
    }

    busy.value = true;
    try {
        const body = await post('enter', {
            guest_id: selected.value.id,
            pin: enteredPin,
            ...(createMode ? { pin_confirmation: enteredConfirmation } : {}),
        });
        const destination = body.revealed ? '/guest' : '/reveal';
        // A guest with no secret name yet picks one before moving on:
        // it is the only name their Secret Santa will ever see. The flag
        // rides on the public guest shape (the list pick, or the enter
        // response when it carries the guest).
        const enteredGuest = body.guest || selected.value;
        if (enteredGuest && enteredGuest.has_secret_name === false) {
            pendingDestination.value = destination;
            step.value = 'secret';
            return;
        }
        router.visit(destination);
    } catch (err) {
        pinError.value = PIN_MESSAGES[err.code] || err.message || 'Something went wrong. Please try again.';
    } finally {
        busy.value = false;
    }
}

function onSecretSaved() {
    router.visit(pendingDestination.value || '/reveal');
}
</script>

<template>
    <Head title="Who are you?" />
    <GuestLayout>
        <!-- Step: invite code -->
        <div v-if="step === 'invite'" class="card slide-in">
            <span class="section-label">Guest entry</span>
            <h1 class="card-title">Enter your invite code</h1>
            <p class="card-subtitle" style="margin-bottom: 22px">
                Your host shared one invite code for the whole party. Type it here to
                get started.
            </p>

            <p v-if="inviteError" class="error-text">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ inviteError }}</span>
            </p>

            <form @submit.prevent="submitInvite">
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
                    <span>{{ busy ? 'Checking...' : 'Continue' }}</span>
                </button>
            </form>

            <div style="text-align: center; margin-top: 6px">
                <button type="button" class="text-btn" @click="scannerOpen = true">
                    <QrCode :size="13" style="vertical-align: -2px" />
                    Scan QR code instead
                </button>
            </div>
        </div>

        <!-- Step: who are you? -->
        <template v-else-if="step === 'search'">
            <Mascot message="Find your name to get started" />

            <div class="card slide-in" style="margin-top: 26px">
                <span class="section-label">Guest entry</span>
                <h1 class="card-title">Who are you?</h1>
                <p class="card-subtitle" style="margin-bottom: 20px">
                    Search for your name, then tap it. You can search by your name,
                    nickname, or another name people call you.
                </p>

                <template v-if="!listLoaded">
                    <p class="muted">Loading the guest list...</p>
                </template>

                <div v-else-if="!party || guests.length === 0" class="empty-state">
                    <UserRound :size="40" />
                    <p class="empty-state-title">No party set up yet</p>
                    <p class="empty-state-text">
                        The host needs to set it up first. Check back once they have
                        shared the invite.
                    </p>
                </div>

                <template v-else>
                    <label class="field">
                        <span class="field-label">Your name</span>
                        <span style="position: relative; display: block">
                            <Search
                                :size="18"
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--placeholder)"
                            />
                            <input
                                v-model="query"
                                class="input"
                                type="text"
                                autocomplete="off"
                                placeholder="Start typing your name"
                                style="padding-left: 42px"
                            />
                        </span>
                    </label>

                    <div v-if="results.length" class="stack" style="gap: 10px">
                        <button
                            v-for="guest in results"
                            :key="guest.id"
                            type="button"
                            class="guest-row"
                            @click="pick(guest)"
                        >
                            <span class="guest-row-main">
                                <span class="guest-row-name">{{ guest.display }}</span>
                                <span v-if="guest.nickname" class="guest-row-sub">
                                    “{{ guest.nickname }}”
                                </span>
                                <span v-if="guest.aliases && guest.aliases.length" class="guest-row-sub">
                                    (also: {{ guest.aliases.join(', ') }})
                                </span>
                            </span>
                            <ArrowLeft :size="18" class="guest-row-arrow" />
                        </button>
                    </div>
                    <p v-else-if="!queryReady" class="muted" style="margin: 4px 0 0">
                        Type at least 2 letters of your name to search.
                    </p>
                    <p v-else class="muted" style="margin: 4px 0 0">
                        No matching names. Check the spelling, or try a nickname.
                    </p>
                </template>
            </div>
        </template>

        <!-- Step: is this you? -->
        <div v-else-if="step === 'confirm' && selected" class="card slide-in">
            <span class="section-label">Guest entry</span>
            <h1 class="card-title">Is this you?</h1>
            <div class="identity-card">
                <p class="identity-name">{{ selected.display }}</p>
                <p v-if="selected.nickname" class="identity-sub">“{{ selected.nickname }}”</p>
                <p v-if="selected.aliases && selected.aliases.length" class="identity-sub">
                    (also: {{ selected.aliases.join(', ') }})
                </p>
            </div>
            <div class="stack">
                <button type="button" class="btn btn-primary btn-block" @click="confirmIdentity">
                    <Check :size="18" />
                    <span>Yes, that's me</span>
                </button>
                <button type="button" class="btn btn-secondary btn-block" @click="backToSearch">
                    <ArrowLeft :size="18" />
                    <span>Not me</span>
                </button>
            </div>
        </div>

        <!-- Step: PIN -->
        <div v-else-if="step === 'pin' && selected" class="card slide-in">
            <span class="section-label">Guest entry</span>
            <h1 class="card-title">{{ selected.needs_pin ? 'Create your PIN' : 'Enter your PIN' }}</h1>
            <p class="card-subtitle" style="margin-bottom: 22px">
                <template v-if="selected.needs_pin">
                    Pick a 4-digit PIN you will remember. You will use it if you come
                    back later, so keep it to yourself.
                </template>
                <template v-else>
                    Enter the 4-digit PIN for {{ selected.display }}.
                </template>
            </p>

            <p v-if="pinError" class="error-text">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ pinError }}</span>
            </p>

            <form @submit.prevent="submitPin">
                <label class="field">
                    <span class="field-label">
                        {{ selected.needs_pin ? 'Create your 4-digit PIN' : 'Your 4-digit PIN' }}
                    </span>
                    <input
                        :value="pin"
                        class="input mono pin-input"
                        type="password"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="••••"
                        @input="pin = digitsOnly($event.target.value)"
                    />
                </label>
                <label v-if="selected.needs_pin" class="field">
                    <span class="field-label">Enter it again</span>
                    <input
                        :value="pinConfirmation"
                        class="input mono pin-input"
                        type="password"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="••••"
                        @input="pinConfirmation = digitsOnly($event.target.value)"
                    />
                </label>
                <button type="submit" class="btn btn-primary btn-block" :disabled="busy">
                    <KeyRound :size="18" />
                    <span>{{ busy ? 'One moment...' : selected.needs_pin ? 'Save PIN and continue' : 'Continue' }}</span>
                </button>
            </form>

            <div style="text-align: center; margin-top: 6px">
                <button type="button" class="text-btn" @click="backToSearch">
                    <ArrowLeft :size="13" style="vertical-align: -2px" />
                    Back to the name list
                </button>
            </div>
        </div>

        <!-- Step: pick a secret name (only guests who have none yet) -->
        <div v-else-if="step === 'secret'" class="card slide-in">
            <span class="section-label">Guest entry</span>
            <h1 class="card-title" style="margin-bottom: 14px">Pick your secret name</h1>
            <SecretNameForm @saved="onSecretSaved" />
        </div>

        <QrScanner
            v-if="scannerOpen"
            @scanned="onScanned"
            @close="scannerOpen = false"
            @type-instead="scannerOpen = false"
        />
    </GuestLayout>
</template>

<style scoped>
.guest-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 56px;
    padding: 12px 16px;
    border-radius: 16px;
    border: 1px solid var(--card-border);
    background: var(--glass);
    color: var(--text-primary);
    text-align: left;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}

.guest-row:hover,
.guest-row:active {
    background: var(--glass-strong);
    border-color: rgba(255, 255, 255, 0.28);
}

.guest-row-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.guest-row-name {
    font-weight: 700;
    font-size: 1.02rem;
}

.guest-row-sub {
    color: var(--text-secondary);
    font-size: 0.86rem;
}

.guest-row-arrow {
    flex: none;
    color: var(--text-tertiary);
    transform: rotate(180deg);
}

.identity-card {
    border: 1px solid var(--card-border);
    background: var(--glass);
    border-radius: 18px;
    padding: 20px;
    text-align: center;
    margin-bottom: 22px;
}

.identity-name {
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin: 0;
}

.identity-sub {
    color: var(--text-secondary);
    margin: 5px 0 0;
}

.pin-input {
    font-size: 1.3rem;
    letter-spacing: 0.5em;
    text-align: center;
}

.text-btn {
    background: none;
    border: none;
    color: var(--lavender);
    font-size: 0.88rem;
    text-decoration: underline;
    cursor: pointer;
    padding: 6px;
}
</style>
