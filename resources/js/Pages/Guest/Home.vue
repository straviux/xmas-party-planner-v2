<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, CircleAlert, Eye, Gift, Hourglass, LockKeyhole, LogOut, Sparkles, VenetianMask, Wallet } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import Mascot from '../../Components/Mascot.vue';
import RevealStrip from '../../Components/RevealStrip.vue';
import SecretNameForm from '../../Components/SecretNameForm.vue';
import { post } from '../../lib/api';
import { toastError, toastSuccess } from '../../lib/toast';

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
    // Whether the viewer has set their own secret name. The VALUE is
    // never a page prop: it is only ever fetched inside the PIN-guarded
    // show popup (guest_secret_name_view) and wiped when it closes.
    ownHasSecretName: {
        type: Boolean,
        default: false,
    },
    // Monito Monita week summary (theme + own spin state only, never a
    // recipient). May be absent in stale caches, so null by default.
    kringle: {
        type: Object,
        default: null,
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

/* ---------- Your secret name ---------- */

// Only WHETHER the viewer has a secret name lives on this page, never
// the value: the card always renders a mask, and the value itself is
// fetched by PIN inside the show popup below and wiped when it closes.
const hasSecretName = ref(!!props.ownHasSecretName);
const secretPopupOpen = ref(false);

function openSecretPopup() {
    secretPopupOpen.value = true;
}

function closeSecretPopup() {
    secretPopupOpen.value = false;
}

function onSecretSaved() {
    // SecretNameForm emits the stored name; it is deliberately NOT kept
    // here, so the value never sits in page state or renders anywhere.
    hasSecretName.value = true;
    secretPopupOpen.value = false;
    toastSuccess('Your secret name is saved.');
}

/* ---------- Show my secret name (guest_secret_name_view) ---------- */

// Mirrors the reveal-again popup below: PIN first, then the value, and
// only inside this popup. closeShowPopup() wipes the PIN and the
// fetched value together, so neither ever persists on the page.
const showPopupOpen = ref(false);
const showStep = ref('pin'); // 'pin' | 'result' | 'edit'
const showPin = ref('');
const showPinError = ref('');
const showChecking = ref(false);
const showPinInput = ref(null);
const shownSecretName = ref(null);
// Remount lever for the edit step's SecretNameForm: bumped on every
// popup open and every entry into the edit step, so the form always
// mounts fresh from shownSecretName and a value typed in a previous
// popup session can never linger in its field.
const showEditKey = ref(0);

function openShowPopup() {
    showPin.value = '';
    showPinError.value = '';
    showStep.value = 'pin';
    shownSecretName.value = null;
    showEditKey.value++;
    showPopupOpen.value = true;
    nextTick(() => {
        if (showPinInput.value) showPinInput.value.focus();
    });
}

function closeShowPopup() {
    showPopupOpen.value = false;
    showStep.value = 'pin';
    showPin.value = '';
    showPinError.value = '';
    showChecking.value = false;
    shownSecretName.value = null;
    showEditKey.value++;
}

function openShowEdit() {
    // The only prefill of the secret-name form anywhere: the value is
    // in popup state solely because the PIN was just verified.
    showEditKey.value++;
    showStep.value = 'edit';
}

function backToShowResult() {
    showEditKey.value++;
    showStep.value = 'result';
}

function onShowEditSaved() {
    // The saved value is deliberately NOT kept: saving ends the popup
    // session with the same full wipe as any other close.
    hasSecretName.value = true;
    closeShowPopup();
    toastSuccess('Your secret name is saved.');
}

function showBackdropClick() {
    if (!showChecking.value) {
        closeShowPopup();
    }
}

async function submitShowPin() {
    if (showChecking.value) return;
    showPinError.value = '';
    if (showPin.value.length !== 4) {
        showPinError.value = 'Enter all 4 digits of your PIN.';
        return;
    }
    showChecking.value = true;
    try {
        const body = await post('guest_secret_name_view', { pin: showPin.value });
        shownSecretName.value = body.secret_name || null;
        showPin.value = '';
        showStep.value = 'result';
    } catch (err) {
        if (err.code === 'pin_incorrect') {
            showPinError.value = 'That PIN does not match.';
            showPin.value = '';
            nextTick(() => {
                if (showPinInput.value) showPinInput.value.focus();
            });
        } else if (err.code === 'pin_must_be_4_digits') {
            showPinError.value = 'Enter all 4 digits of your PIN.';
        } else if (err.code === 'pin_required_create') {
            showPinError.value = 'You have not set a PIN yet. Sign out and enter again to create one.';
        } else if (err.code === 'secret_name_unset') {
            // The card thought a name was set but the server disagrees:
            // drop back to the pick-one prompt state.
            closeShowPopup();
            hasSecretName.value = false;
            return;
        } else if (err.code === 'not_claimed') {
            closeShowPopup();
            toastError('Your session has ended. Enter your name and PIN to continue.');
            router.visit('/enter');
            return;
        } else {
            toastError(err.message || 'Something went wrong. Please try again.');
        }
    } finally {
        showChecking.value = false;
    }
}

/* ---------- Show my recipient again (reveal_again) ---------- */

// The popup is the only place the recipient's secret name ever appears
// on this page: it lives in this local state, which closePopup() wipes,
// and the dashboard itself never renders it.
const popupOpen = ref(false);
const popupStep = ref('pin'); // 'pin' | 'result'
const pin = ref('');
const pinError = ref('');
const checking = ref(false);
const pinInput = ref(null);
const againRecipient = ref(null);
const againBudget = ref(null);
// Reset lever for the popup's RevealStrip: bumped on every popup open and
// close so each use starts with a fresh, covered strip.
const againReset = ref(0);
// True once the popup strip's slide has finished and the name is uncovered.
const stripDone = ref(false);

const digitsOnly = (value) => value.replace(/\D/g, '').slice(0, 4);

function openPopup() {
    pin.value = '';
    pinError.value = '';
    popupStep.value = 'pin';
    againRecipient.value = null;
    againBudget.value = null;
    againReset.value++;
    stripDone.value = false;
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
    againReset.value++;
    stripDone.value = false;
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
    if (event.key !== 'Escape') return;
    if (popupOpen.value && !checking.value) {
        closePopup();
    }
    if (showPopupOpen.value && !showChecking.value) {
        closeShowPopup();
    }
    if (secretPopupOpen.value) {
        closeSecretPopup();
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
                    You already revealed who you are buying for. If their secret
                    name slipped your mind, enter your PIN to see it again.
                </p>
                <button type="button" class="btn btn-primary" @click="openPopup">
                    <Eye :size="16" />
                    <span>Show my recipient again</span>
                </button>
            </div>

            <div class="card dash-span">
                <span class="section-label">
                    <VenetianMask :size="12" style="vertical-align: -1px" />
                    Your secret name
                </span>
                <template v-if="hasSecretName">
                    <!-- The value itself is never rendered here: a fixed
                         mask only, so a glance at the phone shows nothing.
                         The PIN popup is the one place it can be seen. -->
                    <p class="dash-value secret-mask" aria-hidden="true">••••••••</p>
                    <p class="dash-sub" style="margin-bottom: 6px">Your secret name is hidden.</p>
                    <p class="dash-sub" style="margin-bottom: 16px">
                        Enter your PIN to see it. It stays hidden here so nobody
                        glancing at your phone can read it.
                    </p>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px">
                        <button type="button" class="btn btn-primary" @click="openShowPopup">
                            <Eye :size="16" />
                            <span>Show my secret name</span>
                        </button>
                        <button type="button" class="btn btn-secondary" @click="openSecretPopup">
                            <VenetianMask :size="16" />
                            <span>Change my secret name</span>
                        </button>
                    </div>
                </template>
                <template v-else>
                    <p class="dash-sub" style="margin: 10px 0 16px">
                        You have not picked your secret name yet. It is the only
                        name your Secret Santa will see, so pick one before
                        they reveal.
                    </p>
                    <button type="button" class="btn btn-primary" @click="openSecretPopup">
                        <VenetianMask :size="16" />
                        <span>Pick my secret name</span>
                    </button>
                </template>
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
                <Link href="/guest/wishlists?view=mine" class="btn btn-secondary" style="margin-top: 14px">
                    <Gift :size="15" />
                    <span>Open my wishes</span>
                </Link>
            </div>

            <div v-if="kringle && kringle.active" class="card dash-span">
                <span class="section-label">
                    <Sparkles :size="12" style="vertical-align: -1px" />
                    Monito Monita
                </span>
                <p class="dash-sub" style="margin: 10px 0 6px">
                    This week's theme: {{ kringle.theme }}
                </p>
                <p class="dash-sub" style="margin-bottom: 16px">
                    {{ kringle.spun ? 'You have drawn your Monito for this week.' : 'You have not spun yet this week.' }}
                </p>
                <Link href="/guest/kringle" class="btn btn-secondary">
                    <Sparkles :size="15" />
                    <span>{{ kringle.spun ? 'See my Monito' : 'Spin for my Monito' }}</span>
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

        <!-- Show my recipient again: PIN check, then the secret name, in a
             popup only. The name is never rendered on the dashboard itself. -->
        <div v-if="popupOpen" class="modal-backdrop" @click.self="backdropClick">
            <div class="modal-card pop-in" role="dialog" aria-modal="true" aria-label="Show my recipient again">
                <template v-if="popupStep === 'pin'">
                    <h3 class="modal-title">Show my recipient again</h3>
                    <p class="muted" style="margin: 0 0 18px; line-height: 1.55">
                        Enter your 4-digit PIN and we will show you the secret
                        name of the person you are buying for.
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
                                <span>{{ checking ? 'Checking...' : 'Show my recipient' }}</span>
                            </button>
                        </div>
                    </form>
                </template>

                <template v-else-if="againRecipient && !againRecipient.secret_name">
                    <!-- The recipient has not picked a secret name yet, so
                         there is nothing to slide open: the result block
                         keeps the success shape, with a placeholder
                         standing in for the name. -->
                    <div style="text-align: center">
                        <Hourglass :size="30" style="color: var(--text-tertiary)" />
                        <h3 class="modal-title" style="margin-top: 10px">Your recipient is still choosing</h3>
                        <p class="buying-for">
                            <span class="buying-for-label">You are buying for</span>
                            <span class="buying-for-placeholder" role="img" aria-label="Secret name not picked yet">???</span>
                        </p>
                        <p class="muted" style="margin: 0; line-height: 1.55">
                            Your person has not picked their secret name yet.
                            Check again later from your home page, under Show
                            my recipient again.
                        </p>
                    </div>
                    <p v-if="againBudget" class="again-budget" style="text-align: center">
                        <Gift :size="17" style="vertical-align: -3px" />
                        Gift budget: <strong>{{ againBudget }}</strong>
                    </p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-primary" @click="closePopup">
                            <span>Close</span>
                        </button>
                    </div>
                </template>

                <template v-else-if="againRecipient">
                    <!-- The secret name is already fetched at this point;
                         the strip is a pure presentation reveal, slid by
                         the guest. No wishlist link: wishlists sit under
                         real names and would give the game away. -->
                    <RevealStrip
                        :recipient="againRecipient"
                        :fail-signal="againReset"
                        @done="stripDone = true"
                    />
                    <template v-if="stripDone">
                        <p v-if="againBudget" class="again-budget">
                            <Gift :size="17" style="vertical-align: -3px" />
                            Gift budget: <strong>{{ againBudget }}</strong>
                        </p>
                        <p class="muted" style="margin: 18px 0 0; line-height: 1.55">
                            Write this down somewhere private.
                        </p>
                    </template>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-primary" @click="closePopup">
                            <span>Close</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Your secret name: pick one, or change the one you have. The
             form deliberately starts blank: the current value is unknown
             to this page by design, so there is nothing to prefill. -->
        <div v-if="secretPopupOpen" class="modal-backdrop" @click.self="closeSecretPopup">
            <div class="modal-card pop-in" role="dialog" aria-modal="true" aria-label="Your secret name">
                <h3 class="modal-title">{{ hasSecretName ? 'Change my secret name' : 'Pick my secret name' }}</h3>
                <SecretNameForm @saved="onSecretSaved" />
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeSecretPopup">
                        <span>Cancel</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Show my secret name: PIN check, then the value, in a popup
             only. The result step can swap to an edit step prefilled
             with that just-verified value; closing the popup by any
             route, from any step, wipes the PIN and the value. -->
        <div v-if="showPopupOpen" class="modal-backdrop" @click.self="showBackdropClick">
            <div
                class="modal-card pop-in"
                role="dialog"
                aria-modal="true"
                :aria-label="showStep === 'pin' ? 'Show my secret name' : showStep === 'result' ? 'Your secret name' : 'Edit secret name'"
            >
                <template v-if="showStep === 'pin'">
                    <h3 class="modal-title">Show my secret name</h3>
                    <p class="muted" style="margin: 0 0 18px; line-height: 1.55">
                        Enter your PIN and we will show your secret name.
                    </p>

                    <p v-if="showPinError" class="error-text" style="margin-bottom: 14px">
                        <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                        <span>{{ showPinError }}</span>
                    </p>

                    <form @submit.prevent="submitShowPin">
                        <label class="field">
                            <span class="field-label">Your 4-digit PIN</span>
                            <input
                                ref="showPinInput"
                                :value="showPin"
                                class="input mono pin-input"
                                type="password"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="4"
                                autocomplete="off"
                                placeholder="••••"
                                :disabled="showChecking"
                                @input="showPin = digitsOnly($event.target.value)"
                            />
                        </label>
                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :disabled="showChecking"
                                @click="closeShowPopup"
                            >
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="showChecking">
                                <Eye :size="16" />
                                <span>{{ showChecking ? 'Checking...' : 'Show my secret name' }}</span>
                            </button>
                        </div>
                    </form>
                </template>

                <template v-else-if="showStep === 'result'">
                    <div style="text-align: center">
                        <span class="section-label">
                            <VenetianMask :size="12" style="vertical-align: -1px" />
                            Your secret name
                        </span>
                        <p class="dash-value" style="font-size: 2rem; margin: 12px 0 8px">{{ shownSecretName }}</p>
                        <p class="muted" style="margin: 0; line-height: 1.55">
                            This is the only name your Secret Santa sees. It
                            hides again as soon as you close this.
                        </p>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" @click="openShowEdit">
                            <VenetianMask :size="16" />
                            <span>Edit secret name</span>
                        </button>
                        <button type="button" class="btn btn-primary" @click="closeShowPopup">
                            <span>Close</span>
                        </button>
                    </div>
                </template>

                <template v-else-if="showStep === 'edit'">
                    <h3 class="modal-title">Edit secret name</h3>
                    <SecretNameForm
                        :key="showEditKey"
                        :initial="shownSecretName || ''"
                        @saved="onShowEditSaved"
                    />
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" @click="backToShowResult">
                            <span>Back</span>
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

/* The masked stand-in for the guest's own secret name on the card:
   fixed bullets, spread out like a value, revealing nothing. */
.secret-mask {
    font-size: 1.5rem;
    letter-spacing: 0.35em;
    user-select: none;
}

.again-budget {
    color: var(--text-secondary);
    margin: 16px 0 0;
}

/* The "You are buying for" result block, shared in shape with the
   success state (and with the Reveal page's waiting state, which
   carries the same classes): the label matches RevealStrip's
   under-layer label, and the placeholder sits at the revealed name's
   type scale but muted and italic, so it can never be mistaken for an
   actual secret name. */
.buying-for {
    margin: 14px 0 12px;
}

.buying-for-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
}

.buying-for-placeholder {
    display: block;
    font-size: 1.38rem;
    font-weight: 800;
    font-style: italic;
    letter-spacing: 0.04em;
    line-height: 1.15;
    color: var(--text-tertiary);
    margin-top: 2px;
}
</style>
