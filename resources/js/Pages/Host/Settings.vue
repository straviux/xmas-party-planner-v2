<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, PartyPopper, Save } from 'lucide-vue-next';
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
const hasParty = ref(false);
const partyName = ref('');
const form = ref({ name: '', date: '', budget: '' });
const guestLines = ref('');
const formError = ref('');
const busy = ref(false);

const pageTitle = computed(() => (hasParty.value ? 'Party settings' : 'Create party'));

async function load() {
    loading.value = true;
    try {
        const status = await post('host_status');
        hasParty.value = !!status.exists;
        if (status.exists && status.party) {
            partyName.value = status.party.name || '';
            form.value = {
                name: status.party.name || '',
                date: status.party.date || '',
                budget: status.party.budget || '',
            };
        }
    } catch (err) {
        formError.value = err.message || 'Party settings could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function submit() {
    formError.value = '';
    if (!form.value.name.trim()) {
        formError.value = 'Give your party a name first.';
        return;
    }
    const creating = !hasParty.value;
    busy.value = true;
    try {
        await post('party_update', {
            name: form.value.name.trim(),
            date: form.value.date || null,
            budget: form.value.budget.trim() || null,
        });

        if (creating) {
            const names = guestLines.value
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean);
            let failed = 0;
            for (const display of names) {
                try {
                    await post('host_guest_add', { display, nickname: null, aliases: [] });
                } catch {
                    failed += 1;
                }
            }
            if (failed > 0) {
                toastError(
                    `Party created, but ${failed} guest name${failed === 1 ? '' : 's'} could not be added. Add them on the Guests page.`,
                );
            } else {
                toastSuccess(
                    names.length
                        ? `Party created with ${names.length} guest${names.length === 1 ? '' : 's'}. The draw is done.`
                        : 'Party created. Add guests on the Guests page.',
                );
            }
            guestLines.value = '';
        } else {
            toastSuccess('Party details saved. Guests, claims, wishlists, and votes were not changed.');
        }
        await load();
    } catch (err) {
        formError.value = err.message || 'The party could not be saved.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head :title="pageTitle" />
    <SignInGate v-if="!authed" />
    <HostLayout
        v-else
        :party-name="partyName"
        :username="props.username"
        :title="pageTitle"
        :subtitle="
            hasParty
                ? 'Editing details only — this never touches guests, claims, wishlists, or votes.'
                : 'Set up the party and its guest list in one go. The Secret Santa draw runs as soon as guests are added.'
        "
    >
        <section class="host-panel">
            <h2 class="host-panel-title" style="display: flex; align-items: center; gap: 9px">
                <PartyPopper :size="19" style="color: var(--host-teal)" />
                {{ hasParty ? 'Party details' : 'Your party' }}
            </h2>

            <p v-if="formError" class="error-text" style="margin-top: 14px">
                <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                <span>{{ formError }}</span>
            </p>
            <p v-if="loading" class="muted" style="margin-top: 12px">Loading...</p>

            <form v-else style="margin-top: 16px" @submit.prevent="submit">
                <div class="form-grid">
                    <label class="field span-2">
                        <span class="field-label">Party name (required)</span>
                        <input v-model="form.name" class="input" type="text" required placeholder="For example: YSE Christmas Party 2026" />
                    </label>
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input v-model="form.date" class="input" type="date" />
                    </label>
                    <label class="field">
                        <span class="field-label">Gift budget</span>
                        <input v-model="form.budget" class="input" type="text" placeholder="For example: PHP 500" />
                    </label>
                </div>

                <label v-if="!hasParty" class="field">
                    <span class="field-label">Guest list — one display name per line</span>
                    <textarea
                        v-model="guestLines"
                        class="textarea"
                        rows="8"
                        placeholder="MARIA SANTOS&#10;JUAN DELA CRUZ&#10;ANA REYES"
                    ></textarea>
                    <span class="muted small" style="display: block; margin-top: 6px">
                        Nicknames, aliases, and PINs can be added per guest afterwards on the
                        Guests page. With two or more guests, everyone is drawn a recipient
                        automatically — nobody draws themselves.
                    </span>
                </label>

                <div class="row-actions" style="margin-top: 6px">
                    <button type="submit" class="btn btn-teal" :disabled="busy">
                        <Save :size="16" />
                        <span>{{ busy ? 'Saving...' : hasParty ? 'Save party details' : 'Create party' }}</span>
                    </button>
                </div>
            </form>
        </section>
    </HostLayout>
</template>
