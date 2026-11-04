<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, CircleAlert, Eye, Gift, LockKeyhole, LogOut, Wallet } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import { post } from '../../lib/api';
import { toastError } from '../../lib/toast';

const props = defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
    counts: {
        type: Object,
        default: () => ({ locked: 0, total: 0, wishes: 0 }),
    },
});

const signingOut = ref(false);

const lockedPercent = computed(() => {
    const total = props.counts?.total || 0;
    if (!total) return 0;
    return Math.min(100, Math.round(((props.counts?.locked || 0) / total) * 100));
});

const partyDate = computed(() => {
    if (!props.party?.date) return '';
    const parsed = new Date(`${props.party.date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return props.party.date;
    return parsed.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
});

const firstName = computed(() => (props.guest?.display || '').split(' ')[0] || 'there');

async function signOut() {
    signingOut.value = true;
    try {
        await post('guest_logout');
    } catch {
        // Leaving matters more than a server hiccup here.
    } finally {
        router.visit('/');
    }
}

/* ---------- See my person again (reveal_again) ---------- */

// The popup is the only place the recipient name ever appears on this
// page: it lives in this local state, which closePopup() wipes, and the
// dashboard itself never renders it.
const popupOpen = ref(false);
const popupStep = ref('pin'); // 'pin' | 'result'
const pin = ref('');
const pinError = ref('');
const checking = ref(false);
const pinInput = ref(null);
const againRecipient = ref(null);
const againBudget = ref(null);

const digitsOnly = (value) => value.replace(/\D/g, '').slice(0, 4);

function openPopup() {
    pin.value = '';
    pinError.value = '';
    popupStep.value = 'pin';
    againRecipient.value = null;
    againBudget.value = null;
    popupOpen.value = true;
    nextTick(() => {
        if (pinInput.value) pinInput.value.focus();
    });
}

function closePopup() {
    popupOpen.value = false;
    popupStep.value = 'pin';
    pin.value = '';
    pinError.value = '';
    checking.value = false;
    againRecipient.value = null;
    againBudget.value = null;
}

function backdropClick() {
    if (!checking.value) {
        closePopup();
    }
}

async function submitPin() {
    if (checking.value) return;
    pinError.value = '';
    if (pin.value.length !== 4) {
        pinError.value = 'Your PIN must be exactly 4 digits.';
        return;
    }
    checking.value = true;
    try {
        const body = await post('reveal_again', { pin: pin.value });
        againRecipient.value = body.recipient || null;
        againBudget.value = body.budget ?? props.party?.budget ?? null;
        pin.value = '';
        popupStep.value = 'result';
    } catch (err) {
        if (err.code === 'pin_incorrect') {
            pinError.value = 'That PIN is not right. Try again.';
            pin.value = '';
            nextTick(() => {
                if (pinInput.value) pinInput.value.focus();
            });
        } else if (err.code === 'pin_must_be_4_digits') {
            pinError.value = 'Your PIN must be exactly 4 digits.';
        } else if (err.code === 'pin_required_create') {
            pinError.value = 'Create a 4-digit PIN to continue.';
        } else if (err.code === 'not_revealed') {
            closePopup();
            router.visit('/reveal');
            return;
        } else if (err.code === 'not_claimed') {
            closePopup();
            toastError('Your session has ended. Enter your name and PIN to continue.');
            router.visit('/enter');
            return;
        } else {
            toastError(err.message || 'Something went wrong. Please try again.');
        }
    } finally {
        checking.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && popupOpen.value && !checking.value) {
        closePopup();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Head title="My party home" />
    <GuestLayout dock wide>
        <!-- Mascot row in the page flow at the top of the dashboard, so it
             never floats over content or the dock. -->
        <Mascot :message="`Welcome back, ${firstName}!`" />

        <div class="dash-grid slide-in" style="margin-top: 26px">
            <div class="card dash-span">
                <span class="section-label">Your party</span>
                <h1 class="card-title" style="margin-bottom: 6px">{{ party?.name || 'Christmas Party' }}</h1>
                <p class="card-subtitle" style="margin: 0">
                    Hi {{ guest?.nickname || guest?.display }}, you are in. Your person
                    stays a secret now, even from the host.
                </p>
                <p v-if="partyDate" class="dash-meta">
                    <CalendarDays :size="16" style="vertical-align: -3px" />
                    {{ partyDate }}
                </p>
            </div>

            <div v-if="guest?.revealed" class="card dash-span">
                <span class="section-label">
                    <Eye :size="12" style="vertical-align: -1px" />
                    Your Secret Santa
                </span>
                <p class="dash-sub" style="margin: 10px 0 16px">
                    You already revealed your person. If the name slipped your
                    mind, enter your PIN to see it again.
                </p>
                <button type="button" class="btn btn-primary" @click="openPopup">
                    <Eye :size="16" />
                    <span>See my person again</span>
                </button>
            </div>

            <div class="card">
                <span class="section-label">
                    <LockKeyhole :size="12" style="vertical-align: -1px" />
                    Names locked
                </span>
                <p class="dash-value">{{ counts?.locked || 0 }} of {{ counts?.total || 0 }}</p>
                <p class="dash-sub">names locked so far</p>
                <div class="progress-track" style="margin-top: 14px">
                    <div class="progress-fill" :style="{ width: `${lockedPercent}%` }"></div>
                </div>
            </div>

            <div class="card">
                <span class="section-label">
                    <Wallet :size="12" style="vertical-align: -1px" />
                    Gift budget
                </span>
                <p class="dash-value">{{ party?.budget || 'Not set' }}</p>
                <p class="dash-sub">the guide for every gift at this party</p>
            </div>

            <div class="card">
                <span class="section-label">
                    <Gift :size="12" style="vertical-align: -1px" />
                    My wishes
                </span>
                <p class="dash-value">{{ counts?.wishes || 0 }}</p>
                <p class="dash-sub">wishes on your own list</p>
                <Link href="/guest/gifts" class="btn btn-secondary" style="margin-top: 14px">
                    <Gift :size="15" />
                    <span>Open my wishlist</span>
                </Link>
            </div>

            <div class="card dash-span">
                <span class="section-label">Done for now?</span>
                <p class="dash-sub" style="margin-bottom: 16px">
                    Signing out does not unlock your name or undo your reveal. Just
                    enter your PIN again when you come back.
                </p>
                <button type="button" class="btn btn-secondary" :disabled="signingOut" @click="signOut">
                    <LogOut :size="16" />
                    <span>{{ signingOut ? 'Signing out...' : 'Sign out' }}</span>
                </button>
            </div>
        </div>

        <!-- See my person again: PIN check, then the name, in a popup only.
             The name is never rendered on the dashboard itself. -->
        <div v-if="popupOpen" class="modal-backdrop" @click.self="backdropClick">
            <div class="modal-card pop-in" role="dialog" aria-modal="true" aria-label="See my person again">
                <template v-if="popupStep === 'pin'">
                    <h3 class="modal-title">See my person again</h3>
                    <p class="muted" style="margin: 0 0 18px; line-height: 1.55">
                        Enter your 4-digit PIN and we will show you who you are
                        buying for.
                    </p>

                    <p v-if="pinError" class="error-text" style="margin-bottom: 14px">
                        <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                        <span>{{ pinError }}</span>
                    </p>

                    <form @submit.prevent="submitPin">
                        <label class="field">
                            <span class="field-label">Your 4-digit PIN</span>
                            <input
                                ref="pinInput"
                                :value="pin"
                                class="input mono pin-input"
                                type="password"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="4"
                                autocomplete="off"
                                placeholder="••••"
                                :disabled="checking"
                                @input="pin = digitsOnly($event.target.value)"
                            />
                        </label>
                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :disabled="checking"
                                @click="closePopup"
                            >
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="checking">
                                <Eye :size="16" />
                                <span>{{ checking ? 'Checking...' : 'Show my person' }}</span>
                            </button>
                        </div>
                    </form>
                </template>

                <template v-else-if="againRecipient">
                    <span class="section-label">You are buying for</span>
                    <p class="again-name">{{ againRecipient.display }}</p>
                    <p v-if="againRecipient.nickname" class="again-sub">“{{ againRecipient.nickname }}”</p>
                    <p v-if="againBudget" class="again-budget">
                        <Gift :size="17" style="vertical-align: -3px" />
                        Gift budget: <strong>{{ againBudget }}</strong>
                    </p>
                    <p class="muted" style="margin: 18px 0 0; line-height: 1.55">
                        Write this down somewhere private.
                    </p>
                    <div class="modal-actions">
                        <Link href="/guest/wishlists" class="btn btn-secondary" @click="closePopup">
                            <Gift :size="16" />
                            <span>See their wishlist</span>
                        </Link>
                        <button type="button" class="btn btn-primary" @click="closePopup">
                            <span>Close</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </GuestLayout>
</template>

<style scoped>
.dash-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

@media (min-width: 640px) {
    .dash-grid {
        grid-template-columns: 1fr 1fr;
    }

    .dash-span {
        grid-column: 1 / -1;
    }
}

@media (min-width: 1024px) {
    .dash-grid {
        grid-template-columns: 1fr 1fr 1fr;
    }
}

.dash-value {
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1.1;
    margin: 10px 0 4px;
}

.dash-sub {
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.5;
}

.dash-meta {
    color: var(--text-secondary);
    margin: 14px 0 0;
    font-weight: 600;
}

.pin-input {
    font-size: 1.3rem;
    letter-spacing: 0.5em;
    text-align: center;
}

.again-name {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    line-height: 1.15;
    margin: 6px 0 0;
}

.again-sub {
    color: var(--text-secondary);
    margin: 6px 0 0;
    font-size: 1.02rem;
}

.again-budget {
    color: var(--text-secondary);
    margin: 16px 0 0;
}
</style>
