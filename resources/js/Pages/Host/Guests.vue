<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, KeyRound, Pencil, Plus, Search, Trash2, Users, X } from 'lucide-vue-next';
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
const guests = ref([]);
const partyName = ref('');
const hasParty = ref(false);
const search = ref('');
const bannerError = ref('');

const form = ref({ display: '', nickname: '', aliases: '', pin: '' });
const editingId = ref(null);
const formError = ref('');
const busy = ref(false);
const formOpen = ref(false);
const displayInput = ref(null);
const removeTarget = ref(null);
const pinResetTarget = ref(null);
const pinBusy = ref(false);

async function resetPin(guest) {
    pinBusy.value = true;
    bannerError.value = '';
    try {
        await post('host_reset_pin', { guest_id: guest.id });
        toastSuccess(`${guest.display}'s PIN has been cleared`);
        pinResetTarget.value = null;
        await load();
    } catch (err) {
        pinResetTarget.value = null;
        showActionError(err, 'That PIN could not be reset.');
    } finally {
        pinBusy.value = false;
    }
}

function aliasesList(guest) {
    if (Array.isArray(guest.aliases)) return guest.aliases;
    if (typeof guest.aliases === 'string') {
        try {
            const parsed = JSON.parse(guest.aliases);
            return Array.isArray(parsed) ? parsed : [guest.aliases];
        } catch {
            return [guest.aliases];
        }
    }
    return [];
}

function guestStatus(guest) {
    if (guest.revealed) return { label: 'Revealed', cls: 'badge-revealed' };
    if (guest.claimed) return { label: 'Locked', cls: 'badge-locked' };
    return { label: 'Awaiting', cls: 'badge-awaiting' };
}

const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return guests.value;
    return guests.value.filter((guest) => {
        const haystack = [guest.display, guest.nickname, ...aliasesList(guest)]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return haystack.includes(needle);
    });
});

async function load() {
    loading.value = true;
    try {
        const status = await post('host_status');
        hasParty.value = !!status.exists;
        partyName.value = status.exists && status.party ? status.party.name : '';
        guests.value = status.guests || [];
    } catch (err) {
        bannerError.value = err.message || 'The guest list could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && formOpen.value && !busy.value && !removeTarget.value && !pinResetTarget.value) {
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

function showActionError(err, fallback) {
    if (err.code === 'guests_locked') {
        bannerError.value =
            err.message ||
            'Guests cannot be changed after names have been locked. Reset the claims first, then try again.';
    } else if (err.code === 'not_set_up') {
        bannerError.value = 'Set up your party in Party settings before adding guests.';
    } else {
        bannerError.value = err.message || fallback;
    }
}

function resetForm() {
    form.value = { display: '', nickname: '', aliases: '', pin: '' };
    editingId.value = null;
    formError.value = '';
}

async function focusDisplay() {
    await nextTick();
    displayInput.value?.focus();
}

function openCreate() {
    resetForm();
    formOpen.value = true;
    focusDisplay();
}

function closeForm() {
    if (busy.value) return;
    formOpen.value = false;
    resetForm();
}

function startEdit(guest) {
    editingId.value = guest.id;
    formError.value = '';
    form.value = {
        display: guest.display || '',
        nickname: guest.nickname || '',
        aliases: aliasesList(guest).join(', '),
        pin: '',
    };
    formOpen.value = true;
    focusDisplay();
}

async function submit() {
    formError.value = '';
    bannerError.value = '';
    const pin = form.value.pin.trim();
    if (pin && !/^\d{4}$/.test(pin)) {
        formError.value = 'A PIN must be exactly 4 digits, or left blank so the guest makes their own.';
        return;
    }
    const payload = {
        display: form.value.display.trim(),
        nickname: form.value.nickname.trim() || null,
        aliases: form.value.aliases
            .split(',')
            .map((a) => a.trim())
            .filter(Boolean),
    };
    if (pin) payload.pin = pin;

    busy.value = true;
    try {
        if (editingId.value) {
            await post('host_guest_update', { id: editingId.value, ...payload });
            toastSuccess('Guest updated. The draw was re-done for everyone.');
        } else {
            await post('host_guest_add', payload);
            toastSuccess('Guest added. The draw was re-done for everyone.');
        }
        resetForm();
        formOpen.value = false;
        await load();
    } catch (err) {
        if (err.code === 'guests_locked' || err.code === 'not_set_up') {
            formOpen.value = false;
            resetForm();
            showActionError(err, 'That change could not be saved.');
        } else {
            formError.value = err.message || 'That guest could not be saved.';
        }
    } finally {
        busy.value = false;
    }
}

async function removeGuest(guest) {
    bannerError.value = '';
    busy.value = true;
    try {
        await post('host_guest_remove', { id: guest.id });
        toastSuccess(`${guest.display} was removed, along with their wishlist.`);
        removeTarget.value = null;
        if (editingId.value === guest.id) {
            resetForm();
            formOpen.value = false;
        }
        await load();
    } catch (err) {
        removeTarget.value = null;
        showActionError(err, 'That guest could not be removed.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Guests" />
    <SignInGate v-if="!authed" />
    <HostLayout
        v-else
        :party-name="partyName"
        :username="props.username"
        title="Guests"
        subtitle="The full guest list. While nobody has locked a name, changes re-do the draw automatically."
    >
        <template #actions>
            <button v-if="hasParty" type="button" class="btn btn-teal btn-sm" @click="openCreate">
                <Plus :size="15" />
                <span>Add guest</span>
            </button>
        </template>

        <p v-if="bannerError" class="error-text">
            <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
            <span>{{ bannerError }}</span>
        </p>

        <section v-if="!loading && !hasParty" class="host-panel empty-state">
            <Users :size="40" />
            <h2 class="empty-state-title">No party yet</h2>
            <p class="empty-state-text">
                Create your party in Party settings first. You can add the whole
                guest list there in one go.
            </p>
        </section>

        <template v-else>
            <section class="host-panel">
                <div style="display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 8px">
                    <h2 class="host-panel-title" style="margin: 0">Guest list</h2>
                    <label style="position: relative; display: block; width: 300px; max-width: 100%">
                        <Search :size="16" style="position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary)" />
                        <input
                            v-model="search"
                            class="input"
                            style="min-height: 42px; padding-left: 38px"
                            type="search"
                            placeholder="Search name, nickname, alias"
                        />
                    </label>
                </div>

                <p v-if="loading" class="muted">Loading guests...</p>
                <table v-else-if="filtered.length" class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Nickname</th>
                            <th>Aliases</th>
                            <th>PIN set</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="guest in filtered" :key="guest.id">
                            <td>
                                {{ guest.display }}
                                <span v-if="guest.has_secret_name" class="muted small" style="display: block; margin-top: 2px">
                                    Secret name picked
                                </span>
                                <span v-else class="muted small" style="display: block; margin-top: 2px">
                                    No secret name yet
                                </span>
                            </td>
                            <td>{{ guest.nickname || '—' }}</td>
                            <td>{{ aliasesList(guest).join(', ') || '—' }}</td>
                            <td>{{ guest.pin_set ? 'Yes' : 'No' }}</td>
                            <td>
                                <span class="badge" :class="guestStatus(guest).cls">{{ guestStatus(guest).label }}</span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="startEdit(guest)">
                                        <Pencil :size="14" />
                                        <span>Edit</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        :disabled="!guest.pin_set || busy"
                                        :title="
                                            guest.pin_set
                                                ? `Clear the PIN for ${guest.display} so they can make a new one`
                                                : 'This guest has no PIN set, so there is nothing to reset'
                                        "
                                        @click="pinResetTarget = guest"
                                    >
                                        <KeyRound :size="14" />
                                        <span>Reset PIN</span>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="removeTarget = guest">
                                        <Trash2 :size="14" />
                                        <span>Remove</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted" style="margin: 12px 0 2px">
                    {{ search ? 'No guests match that search.' : 'No guests yet. Use Add guest to add your first guest.' }}
                </p>
            </section>
        </template>

        <div v-if="formOpen" class="modal-backdrop" @click.self="closeForm">
            <div
                class="modal-card pop-in"
                style="width: min(94vw, 680px)"
                role="dialog"
                aria-modal="true"
                :aria-label="editingId ? 'Edit guest' : 'Add guest'"
            >
                <h3 class="modal-title">{{ editingId ? 'Edit guest' : 'Add guest' }}</h3>
                <p v-if="formError" class="error-text" style="margin-top: 12px">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ formError }}</span>
                </p>
                <form style="margin-top: 14px" @submit.prevent="submit">
                    <div class="form-grid">
                        <label class="field">
                            <span class="field-label">Display name (required)</span>
                            <input
                                ref="displayInput"
                                v-model="form.display"
                                class="input"
                                type="text"
                                required
                                placeholder="For example: MARIA SANTOS"
                            />
                        </label>
                        <label class="field">
                            <span class="field-label">Nickname</span>
                            <input v-model="form.nickname" class="input" type="text" placeholder="For example: Mai" />
                        </label>
                        <label class="field">
                            <span class="field-label">Aliases (comma-separated, for search)</span>
                            <input v-model="form.aliases" class="input" type="text" placeholder="For example: Ate Mai, Maria" />
                        </label>
                        <label class="field">
                            <span class="field-label">PIN (optional, 4 digits)</span>
                            <input
                                v-model="form.pin"
                                class="input"
                                type="text"
                                inputmode="numeric"
                                maxlength="4"
                                :placeholder="editingId ? 'Leave blank to keep the current PIN' : 'Leave blank: guest makes their own'"
                            />
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="closeForm">
                            <X :size="15" />
                            <span>Cancel</span>
                        </button>
                        <button type="submit" class="btn btn-teal btn-sm" :disabled="busy">
                            <Plus v-if="!editingId" :size="15" />
                            <Pencil v-else :size="15" />
                            <span>{{ busy ? 'Saving...' : editingId ? 'Save changes' : 'Add guest' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            v-if="removeTarget"
            :title="`Remove ${removeTarget.display}?`"
            :body="`This removes ${removeTarget.display} from the party and deletes their wishlist items. Votes they cast are removed too, and the draw is re-done for the remaining guests.`"
            confirm-label="Remove guest"
            danger
            :busy="busy"
            @confirm="removeGuest(removeTarget)"
            @cancel="removeTarget = null"
        />

        <ConfirmModal
            v-if="pinResetTarget"
            :title="`Reset PIN for ${pinResetTarget.display}?`"
            :body="`This clears only the PIN for ${pinResetTarget.display}. Their claim, their wishlist, and their votes are untouched. The next time they enter, they will create a new PIN.`"
            confirm-label="Reset PIN"
            :busy="pinBusy"
            @confirm="resetPin(pinResetTarget)"
            @cancel="pinResetTarget = null"
        />
    </HostLayout>
</template>
