<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    ChartColumn,
    CircleAlert,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    Save,
    Trash2,
    X,
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
const bannerError = ref('');
const partyName = ref('');
const hasParty = ref(false);
const polls = ref([]);

const formOpen = ref(false);
const formMode = ref('create');
const editingId = ref(null);
const formQuestion = ref('');
const formOptions = ref([{ text: '' }, { text: '' }]);
const formError = ref('');
const saving = ref(false);
const questionInput = ref(null);

const busyPollId = ref(null);
const deleteTarget = ref(null);
const deleting = ref(false);

function sameId(a, b) {
    return a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b);
}

async function load() {
    loading.value = true;
    try {
        const status = await post('host_status');
        hasParty.value = !!status.exists;
        partyName.value = status.exists && status.party ? status.party.name : '';
        const result = await post('poll_get');
        polls.value = result.polls || [];
    } catch (err) {
        bannerError.value =
            err.code === 'forbidden'
                ? 'You are signed out. Sign in again to manage polls.'
                : err.message || 'The polls could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && formOpen.value && !saving.value && !deleteTarget.value) {
        closeForm();
    }
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});

async function focusQuestion() {
    await nextTick();
    questionInput.value?.focus();
}

function openCreate() {
    formMode.value = 'create';
    editingId.value = null;
    formQuestion.value = '';
    formOptions.value = [{ text: '' }, { text: '' }];
    formError.value = '';
    formOpen.value = true;
    focusQuestion();
}

function openEdit(poll) {
    formMode.value = 'edit';
    editingId.value = poll.id;
    formQuestion.value = poll.question || '';
    formOptions.value = (poll.options || []).map((option) => ({ id: option.id, text: option.text }));
    if (formOptions.value.length < 2) {
        while (formOptions.value.length < 2) formOptions.value.push({ text: '' });
    }
    formError.value = '';
    formOpen.value = true;
    focusQuestion();
}

function closeForm() {
    if (saving.value) return;
    formOpen.value = false;
    formError.value = '';
    editingId.value = null;
    formMode.value = 'create';
}

function addOptionRow() {
    formOptions.value.push({ text: '' });
}

function removeOptionRow(index) {
    if (formOptions.value.length <= 2) return;
    formOptions.value.splice(index, 1);
}

function plainPollError(err, fallback) {
    if (err.code === 'poll_question_required') return 'Give the poll a question first.';
    if (err.code === 'poll_needs_options') return 'A poll needs at least 2 options with text.';
    if (err.code === 'not_set_up') return 'Set up your party in Party settings before creating polls.';
    if (err.code === 'poll_not_found') return 'That poll no longer exists. The list has been refreshed.';
    if (err.code === 'forbidden') return 'You are signed out. Sign in again to manage polls.';
    return err.message || fallback;
}

async function save() {
    formError.value = '';
    const question = formQuestion.value.trim();
    if (!question) {
        formError.value = 'Give the poll a question first.';
        return;
    }
    if (formOptions.value.some((option) => !option.text.trim())) {
        formError.value = 'Every option needs text, or remove that row.';
        return;
    }
    if (formOptions.value.length < 2) {
        formError.value = 'A poll needs at least 2 options.';
        return;
    }

    saving.value = true;
    try {
        if (formMode.value === 'create') {
            await post('host_poll_create', {
                question,
                options: formOptions.value.map((option) => option.text.trim()),
            });
            toastSuccess('Poll created. Guests can vote now.');
        } else {
            await post('host_poll_update', {
                id: editingId.value,
                question,
                options: formOptions.value.map((option) =>
                    option.id !== undefined && option.id !== null
                        ? { id: option.id, text: option.text.trim() }
                        : { text: option.text.trim() },
                ),
            });
            toastSuccess('Poll saved.');
        }
        closeForm();
        await load();
    } catch (err) {
        formError.value = plainPollError(err, 'That poll could not be saved.');
        if (err.code === 'poll_not_found' || err.code === 'not_set_up') {
            await load();
        }
    } finally {
        saving.value = false;
    }
}

async function togglePoll(poll) {
    busyPollId.value = poll.id;
    try {
        const result = await post('host_poll_toggle', { id: poll.id });
        const index = polls.value.findIndex((item) => sameId(item.id, poll.id));
        if (index !== -1 && result.poll) {
            polls.value.splice(index, 1, result.poll);
        } else {
            await load();
        }
        toastSuccess(result.poll && result.poll.open ? 'Poll reopened.' : 'Poll closed. Guests can no longer vote.');
    } catch (err) {
        toastError(plainPollError(err, 'That poll could not be changed.'));
        if (err.code === 'poll_not_found') await load();
    } finally {
        busyPollId.value = null;
    }
}

async function deletePoll() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        await post('host_poll_delete', { id: deleteTarget.value.id });
        toastSuccess('Poll deleted.');
        if (sameId(editingId.value, deleteTarget.value.id)) closeForm();
        deleteTarget.value = null;
        await load();
    } catch (err) {
        toastError(plainPollError(err, 'That poll could not be deleted.'));
        deleteTarget.value = null;
        if (err.code === 'poll_not_found') await load();
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <Head title="Polls" />
    <SignInGate v-if="!authed" />
    <HostLayout
        v-else
        :party-name="partyName"
        :username="props.username"
        title="Polls"
        subtitle="Party voting, like choosing this year's Christmas theme. Results show counts only, never who voted for what."
    >
        <template #actions>
            <button v-if="hasParty" type="button" class="btn btn-teal btn-sm" @click="openCreate">
                <Plus :size="15" />
                <span>New poll</span>
            </button>
        </template>

        <p v-if="bannerError" class="error-text">
            <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
            <span>{{ bannerError }}</span>
        </p>

        <section v-if="!loading && !hasParty" class="host-panel empty-state">
            <ChartColumn :size="40" />
            <h2 class="empty-state-title">No party yet</h2>
            <p class="empty-state-text">
                Create your party in Party settings first, then you can add polls
                for your guests to vote on.
            </p>
        </section>

        <template v-else>
            <section class="host-panel">
                <h2 class="host-panel-title">All polls</h2>
                <p v-if="loading" class="muted" style="margin: 10px 0 2px">Loading polls...</p>

                <div v-else-if="!polls.length" class="empty-state">
                    <ChartColumn :size="40" />
                    <h3 class="empty-state-title">No polls yet</h3>
                    <p class="empty-state-text">
                        Create your first poll and your guests can vote from the
                        Vote page on their phones.
                    </p>
                    <button type="button" class="btn btn-teal" @click="openCreate">
                        <Plus :size="16" />
                        <span>Create the first poll</span>
                    </button>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Question</th>
                            <th>Status</th>
                            <th>Votes</th>
                            <th>Options</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="poll in polls" :key="poll.id">
                            <td>{{ poll.question }}</td>
                            <td>
                                <span class="badge" :class="poll.open ? 'badge-teal' : 'badge-awaiting'">
                                    {{ poll.open ? 'Open' : 'Closed' }}
                                </span>
                            </td>
                            <td>{{ poll.total || 0 }}</td>
                            <td>{{ (poll.options || []).length }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="openEdit(poll)">
                                        <Pencil :size="14" />
                                        <span>Manage</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        :disabled="busyPollId !== null"
                                        @click="togglePoll(poll)"
                                    >
                                        <Lock v-if="poll.open" :size="14" />
                                        <LockOpen v-else :size="14" />
                                        <span>{{ poll.open ? 'Close' : 'Reopen' }}</span>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="deleteTarget = poll">
                                        <Trash2 :size="14" />
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>

        <div v-if="formOpen" class="modal-backdrop" @click.self="closeForm">
            <div
                class="modal-card pop-in"
                style="width: min(94vw, 640px)"
                role="dialog"
                aria-modal="true"
                :aria-label="formMode === 'create' ? 'Create poll' : 'Edit poll'"
            >
                <h3 class="modal-title">{{ formMode === 'create' ? 'Create poll' : 'Edit poll' }}</h3>
                <p class="muted small" style="margin: 0 0 6px">
                    Guests vote once and can change their vote until you close the poll.
                    Editing an option keeps the votes it already has.
                </p>
                <p v-if="formError" class="error-text" style="margin-top: 12px">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ formError }}</span>
                </p>
                <form style="margin-top: 14px" @submit.prevent="save">
                    <label class="field">
                        <span class="field-label">Question (required)</span>
                        <input
                            ref="questionInput"
                            v-model="formQuestion"
                            class="input"
                            type="text"
                            required
                            placeholder="For example: What should this year's theme be?"
                        />
                    </label>

                    <span class="field-label" style="display: block; margin: 16px 0 8px">Options</span>
                    <div class="stack" style="gap: 9px">
                        <div v-for="(option, index) in formOptions" :key="index" class="poll-option-row">
                            <input
                                v-model="option.text"
                                class="input"
                                type="text"
                                :placeholder="`Option ${index + 1}`"
                                :aria-label="`Option ${index + 1}`"
                            />
                            <button
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="formOptions.length <= 2"
                                :title="formOptions.length <= 2 ? 'A poll needs at least 2 options' : 'Remove this option'"
                                @click="removeOptionRow(index)"
                            >
                                <X :size="14" />
                                <span>Remove</span>
                            </button>
                        </div>
                    </div>
                    <p class="muted small" style="margin: 9px 0 0">A poll needs at least 2 options.</p>

                    <div class="row-actions" style="margin-top: 18px">
                        <button type="button" class="btn btn-secondary btn-sm" @click="addOptionRow">
                            <Plus :size="15" />
                            <span>Add option</span>
                        </button>
                        <button type="submit" class="btn btn-teal btn-sm" :disabled="saving">
                            <Save :size="15" />
                            <span>{{ saving ? 'Saving...' : formMode === 'create' ? 'Create poll' : 'Save changes' }}</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="saving" @click="closeForm">
                            <X :size="15" />
                            <span>Cancel</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            v-if="deleteTarget"
            title="Delete this poll?"
            :body="`${deleteTarget.question} and all of its votes will be deleted. This cannot be undone.`"
            confirm-label="Yes, delete it"
            cancel-label="Keep poll"
            danger
            :busy="deleting"
            @confirm="deletePoll"
            @cancel="deleteTarget = null"
        />
    </HostLayout>
</template>
