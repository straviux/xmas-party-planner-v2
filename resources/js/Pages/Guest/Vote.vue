<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, Trophy, Vote } from 'lucide-vue-next';
import GuestLayout from '../../Components/GuestLayout.vue';
import { post } from '../../lib/api';
import { toastError, toastSuccess } from '../../lib/toast';

defineProps({
    party: {
        type: Object,
        default: null,
    },
    guest: {
        type: Object,
        default: null,
    },
});

const loading = ref(true);
const loadError = ref('');
const polls = ref([]);
const votingPollIds = ref([]);

function isVoting(poll) {
    return votingPollIds.value.some((id) => sameId(id, poll.id));
}

function sameId(a, b) {
    return a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b);
}

function percent(poll, option) {
    if (!poll.total) return 0;
    return Math.round(((option.votes || 0) / poll.total) * 100);
}

function isMine(poll, option) {
    return sameId(poll.my_vote, option.id);
}

function isWinner(poll, option) {
    if (poll.open) return false;
    return (poll.winners || []).some((id) => sameId(id, option.id));
}

function winnerOptions(poll) {
    return (poll.options || []).filter((option) => isWinner(poll, option));
}

function votesLabel(total) {
    return total === 1 ? '1 vote' : `${total || 0} votes`;
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const result = await post('poll_get');
        polls.value = result.polls || [];
    } catch (err) {
        loadError.value = err.message || 'The polls could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function vote(poll, option) {
    if (!poll.open || isVoting(poll)) return;
    if (isMine(poll, option)) return; // Already my vote: nothing to change.

    votingPollIds.value.push(poll.id);
    try {
        const result = await post('poll_vote', { poll_id: poll.id, option_id: option.id });
        const index = polls.value.findIndex((item) => sameId(item.id, poll.id));
        if (index !== -1 && result.poll) {
            polls.value.splice(index, 1, result.poll);
        }
        toastSuccess('Vote saved.');
    } catch (err) {
        if (err.code === 'poll_closed') {
            toastError('This poll is closed.');
            await load();
        } else if (err.code === 'not_claimed') {
            toastError('Enter the party with your name and PIN first, then vote.');
        } else {
            toastError(err.message || 'Your vote could not be saved. Please try again.');
        }
    } finally {
        votingPollIds.value = votingPollIds.value.filter((id) => !sameId(id, poll.id));
    }
}
</script>

<template>
    <Head title="Vote" />
    <GuestLayout dock wide>
        <div class="card slide-in">
            <span class="section-label">Polls</span>
            <h1 class="card-title">Vote</h1>
            <p class="card-subtitle" style="margin: 0">
                Tap an option to vote. You can change your vote any time until the
                host closes the poll. Results show counts only, never who voted.
            </p>
        </div>

        <p v-if="loadError" class="error-text" style="margin-top: 18px">
            <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
            <span>{{ loadError }}</span>
        </p>

        <p v-if="loading" class="muted" style="margin-top: 20px">Loading polls...</p>

        <div v-else-if="!polls.length" class="card slide-in" style="margin-top: 18px">
            <div class="empty-state">
                <Vote :size="40" />
                <p class="empty-state-title">No polls yet</p>
                <p class="empty-state-text">
                    The host has not added any polls yet. When a poll opens, it
                    will show up here for you to vote.
                </p>
            </div>
        </div>

        <section
            v-for="poll in polls"
            :key="poll.id"
            class="card slide-in poll-card"
            :aria-label="poll.question"
        >
            <div class="poll-head">
                <h2 class="poll-question">{{ poll.question }}</h2>
                <span class="badge" :class="poll.open ? 'badge-revealed' : 'badge-awaiting'">
                    {{ poll.open ? 'Open' : 'Closed' }}
                </span>
            </div>
            <p class="muted small" style="margin: 0 0 6px">{{ votesLabel(poll.total) }} so far</p>

            <div
                v-if="!poll.open && winnerOptions(poll).length"
                class="poll-winner-banner"
                style="margin-bottom: 14px"
            >
                <Trophy :size="17" style="flex: none" />
                <span v-if="winnerOptions(poll).length === 1">
                    Winner: <strong>{{ winnerOptions(poll)[0].text }}</strong>
                </span>
                <span v-else>
                    Tie between:
                    <strong>{{ winnerOptions(poll).map((option) => option.text).join(', ') }}</strong>
                </span>
            </div>
            <p v-else-if="!poll.open && !poll.total" class="muted small" style="margin: 0 0 10px">
                This poll closed with no votes.
            </p>

            <div class="poll-options">
                <component
                    :is="poll.open ? 'button' : 'div'"
                    v-for="option in poll.options"
                    :key="option.id"
                    :type="poll.open ? 'button' : undefined"
                    class="poll-option"
                    :class="{
                        mine: isMine(poll, option),
                        winner: isWinner(poll, option),
                        'is-static': !poll.open,
                    }"
                    :disabled="poll.open ? isVoting(poll) : undefined"
                    @click="vote(poll, option)"
                >
                    <span class="poll-option-main">
                        <span class="poll-radio" :class="{ filled: isMine(poll, option) }">
                            <span v-if="isMine(poll, option)" class="poll-radio-dot"></span>
                        </span>
                        <span class="poll-option-text">{{ option.text }}</span>
                        <span v-if="isWinner(poll, option)" class="badge badge-locked">Winner</span>
                    </span>
                    <span class="poll-option-result">
                        {{ percent(poll, option) }}% · {{ votesLabel(option.votes) }}
                    </span>
                    <span class="progress-track poll-bar">
                        <span class="progress-fill" :style="{ width: `${percent(poll, option)}%` }"></span>
                    </span>
                </component>
            </div>

            <p v-if="poll.open" class="muted small" style="margin: 12px 0 0">
                <span v-if="poll.my_vote !== null && poll.my_vote !== undefined">
                    Your vote is marked. Tap another option to change it.
                </span>
                <span v-else>Tap an option above to cast your vote.</span>
            </p>
        </section>
    </GuestLayout>
</template>
