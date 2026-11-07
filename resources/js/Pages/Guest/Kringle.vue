<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import GuestLayout from '../../Components/GuestLayout.vue';
import KringleRoulette from '../../Components/KringleRoulette.vue';
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
    wheelNames: {
        type: Array,
        default: () => [],
    },
    kringle: {
        type: Object,
        default: null,
    },
});

// Local copy of the kringle state: a spin swaps it wholesale once the
// roulette settles, and Inertia prop refreshes sync it back in.
const state = ref(props.kringle);

watch(
    () => props.kringle,
    (value) => {
        state.value = value;
    },
);

// Roulette wiring, mirroring the Reveal page's strip contract: the
// drawn codename only exists here after the spin call returns it. The
// component flickers on `commit`, decelerates onto `result`, and the
// spun view replaces the roulette only when it emits `done`.
const result = ref(undefined);
const failSignal = ref(0);
let pendingState = null;

async function onSpinCommit() {
    try {
        const body = await post('kringle_spin');
        pendingState = body.kringle || null;
        result.value = { name: body.kringle?.recipient?.secret_name ?? null };
    } catch (err) {
        // Back to the unspun view: the reel resets and the guest can
        // read why in the toast.
        failSignal.value++;
        toastError(err.message || 'The spin did not work. Please try again.');
    }
}

function onSpinDone() {
    if (pendingState) {
        state.value = pendingState;
        pendingState = null;
    }
    result.value = undefined;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatDay(iso) {
    if (!iso) return '';
    const date = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(date.getTime())) return iso;
    return `${MONTHS[date.getMonth()]} ${date.getDate()}`;
}

function weekRange(start, end) {
    if (!start || !end) return '';
    return `Week of ${formatDay(start)} to ${formatDay(end)}`;
}

const weeksLeftText = computed(() => {
    const n = state.value?.weeks_left;
    if (n === null || n === undefined) return '';
    return n === 1 ? 'Last theme week before the party' : `${n} theme weeks to go`;
});
</script>

<template>
    <Head title="Monito Monita" />
    <GuestLayout dock wide>
        <!-- Scor stands on the page background, outside any card. -->
        <Mascot message="New theme, new Monito every week!" />

        <div class="card slide-in" style="margin-top: 10px">
            <span class="section-label">Monito Monita</span>

            <p v-if="!state" class="muted" style="margin: 0">Loading this week's theme...</p>

            <!-- The run has ended: the party week gets no theme and
                 no spin. History below still shows the past weeks. -->
            <template v-else-if="!state.active">
                <h1 class="card-title">Monito Monita has wrapped up</h1>
                <p class="card-subtitle" style="margin: 0">
                    The weekly themes have ended for this year. The party is this week.
                </p>
            </template>

            <template v-else>
                <p class="muted small" style="margin: 0 0 8px">
                    {{ weekRange(state.week_start, state.week_end) }}
                </p>
                <h1 class="kringle-theme">{{ state.theme }}</h1>
                <p v-if="weeksLeftText" class="muted" style="margin: 0">{{ weeksLeftText }}</p>

                <!-- Not spun yet: the roulette. The hint sits under it
                     so the guest knows the spin draws their Monito for
                     this week only. -->
                <div v-if="!state.spun" style="margin-top: 20px">
                    <KringleRoulette
                        :names="wheelNames"
                        :result="result"
                        :fail-signal="failSignal"
                        @commit="onSpinCommit"
                        @done="onSpinDone"
                    />
                    <p class="muted small" style="margin: 12px 0 0; text-align: center">
                        Spin to draw who you are buying for this week.
                    </p>
                </div>

                <!-- Spun: the result block keeps the app's reveal shape.
                     A recipient who has not picked a secret name yet
                     stands in as the muted placeholder, never a blank. -->
                <div v-else class="pop-in" style="margin-top: 22px">
                    <div class="result-card">
                        <p class="buying-for">
                            <span class="buying-for-label">You are buying for</span>
                            <span v-if="state.recipient?.secret_name" class="buying-for-name">
                                {{ state.recipient.secret_name }}
                            </span>
                            <span
                                v-else
                                class="buying-for-placeholder"
                                role="img"
                                aria-label="Secret name not picked yet"
                            >???</span>
                        </p>
                        <p v-if="!state.recipient?.secret_name" class="muted" style="margin: 0; line-height: 1.55">
                            Your Monito has not picked a secret name yet.
                        </p>
                    </div>
                    <p class="muted" style="margin: 0; line-height: 1.55">
                        That is your Monito for this week. A fresh spin comes with next week's theme.
                    </p>
                </div>
            </template>
        </div>

        <!-- Past weeks: theme per week plus who this guest drew, from
             their own draws only. A null codename is shown neutrally:
             the payload cannot distinguish "no spin that week" from a
             drawn recipient who never picked a secret name. -->
        <div v-if="state?.history?.length" class="card slide-in" style="margin-top: 16px">
            <span class="section-label">Past weeks</span>
            <div
                v-for="(entry, index) in state.history"
                :key="entry.week_start || index"
                class="history-row"
            >
                <div class="history-main">
                    <span class="muted small">{{ weekRange(entry.week_start, entry.week_end) }}</span>
                    <span class="history-theme">{{ entry.theme }}</span>
                </div>
                <span v-if="entry.recipient_secret_name" class="history-drew">
                    You drew {{ entry.recipient_secret_name }}
                </span>
                <span v-else class="muted">—</span>
            </div>
        </div>
    </GuestLayout>
</template>

<style scoped>
.kringle-theme {
    font-family: var(--font-display);
    font-size: 2.15rem;
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: -0.01em;
    margin: 0 0 10px;
    color: var(--text-primary);
}

/* The "You are buying for" result block, in the same shape as the
   Reveal page's: glass card, accent label, the codename big under it,
   and the muted italic placeholder when there is no codename yet. */
.result-card {
    border: 1px solid var(--card-border);
    background: var(--glass);
    border-radius: 18px;
    padding: 16px 20px;
    text-align: center;
    margin-bottom: 16px;
}

.buying-for {
    margin: 6px 0 10px;
}

.buying-for-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--accent, #c427fb);
}

.buying-for-name {
    display: block;
    font-family: var(--font-display);
    font-size: 1.8rem;
    font-weight: 800;
    line-height: 1.15;
    color: var(--text-primary);
    margin-top: 4px;
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

.history-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--card-border);
}

.history-row:last-child {
    border-bottom: none;
    padding-bottom: 2px;
}

.history-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.history-theme {
    font-weight: 700;
    color: var(--text-primary);
}

.history-drew {
    flex: none;
    font-weight: 600;
    color: var(--text-secondary);
    text-align: right;
}
</style>
