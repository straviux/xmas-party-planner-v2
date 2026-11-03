<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;
use App\Models\Poll;
use App\Models\Vote;
use App\Support\Serializers;
use Illuminate\Support\Facades\DB;

/**
 * Host poll management and guest voting (Slice 4).
 *
 * Rules that live here (spec, Polls):
 * - A poll needs a question and at least two non-empty options.
 * - One vote per guest per poll, enforced by the UNIQUE(poll_id, guest_id)
 *   constraint; voting again while the poll is open changes the vote.
 * - A closed poll refuses all vote changes and exposes its winner(s):
 *   every option tied at the highest count (A9, see shape()).
 * - Results are counts only. No voter name or guest id ever enters a
 *   payload; all poll payloads are built through shape() below, which is
 *   the one place poll shapes are assembled (via Serializers::pollShape).
 */
class PollService
{
    /**
     * @param  array<int, mixed>  $options  option strings (entries may also be
     *                                      ['text' => string] arrays)
     */
    public function create(Party $party, mixed $question, array $options): Poll
    {
        $question = $this->cleanQuestion($question);
        $texts = $this->cleanOptionTexts($options);

        return DB::transaction(function () use ($party, $question, $texts) {
            $poll = $party->polls()->create([
                'question' => $question,
                'open' => true,
            ]);

            foreach ($texts as $position => $text) {
                $poll->options()->create([
                    'text' => $text,
                    'sort_order' => $position,
                ]);
            }

            return $poll;
        });
    }

    /**
     * Edit a poll in place. Incoming options are [{id?, text}] (plain
     * strings are accepted defensively and treated as new options):
     * entries with an id update that option, entries without one create
     * a new option, and existing options missing from the list are
     * deleted (their votes cascade away with them). Votes on kept options
     * are untouched.
     *
     * @param  array<int, mixed>  $options
     */
    public function update(Poll $poll, mixed $question, array $options): Poll
    {
        $question = $this->cleanQuestion($question);
        $entries = $this->cleanOptionEntries($options);

        return DB::transaction(function () use ($poll, $question, $entries) {
            $existing = $poll->options()->get()->keyBy('id');

            // Every incoming id must belong to this poll, checked before
            // anything is mutated.
            foreach ($entries as $entry) {
                if ($entry['id'] !== null && ! $existing->has($entry['id'])) {
                    throw PollException::make('option_not_found', 404);
                }
            }

            $poll->update(['question' => $question]);

            $keepIds = [];
            foreach ($entries as $position => $entry) {
                if ($entry['id'] !== null) {
                    $existing[$entry['id']]->update([
                        'text' => $entry['text'],
                        'sort_order' => $position,
                    ]);
                    $keepIds[] = $entry['id'];
                } else {
                    $created = $poll->options()->create([
                        'text' => $entry['text'],
                        'sort_order' => $position,
                    ]);
                    $keepIds[] = $created->id;
                }
            }

            $poll->options()->whereNotIn('id', $keepIds)->delete();

            return $poll->fresh();
        });
    }

    /** Close an open poll, or reopen a closed one. */
    public function toggle(Poll $poll): Poll
    {
        $poll->update(['open' => ! $poll->open]);

        return $poll;
    }

    /** Delete the poll; its options and their votes cascade. */
    public function delete(Poll $poll): void
    {
        $poll->delete();
    }

    /**
     * Cast or change a guest's vote. The poll row is locked for the whole
     * upsert so two devices voting at once cannot race past the closed
     * check or duplicate the (poll_id, guest_id) row.
     *
     * @return array the fresh poll shape for the voting guest
     */
    public function vote(Guest $guest, Poll $poll, mixed $optionId): array
    {
        return DB::transaction(function () use ($guest, $poll, $optionId) {
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->firstOrFail();

            if (! $locked->open) {
                throw PollException::make('poll_closed', 409);
            }

            $option = is_numeric($optionId)
                ? $locked->options()->whereKey((int) $optionId)->first()
                : null;
            if ($option === null) {
                throw PollException::make('option_not_found', 404);
            }

            Vote::updateOrCreate(
                ['poll_id' => $locked->id, 'guest_id' => $guest->id],
                ['poll_option_id' => $option->id],
            );

            return $this->shape($locked->fresh(), $guest);
        });
    }

    /**
     * The one poll shape used by every endpoint: built by
     * Serializers::pollShape (allowlist) and enriched there with the
     * winner list. This method only gathers the viewer's own vote and
     * the per-option counts; it never exposes who else voted.
     *
     * Shape: {id, question, open, total, options:[{id, text, votes}],
     *         my_vote, winners}
     */
    public function shape(Poll $poll, ?Guest $viewer): array
    {
        $poll->setRelation(
            'options',
            $poll->options()->withCount('votes')->get(),
        );

        $myVote = null;
        if ($viewer !== null) {
            $myVote = Vote::query()
                ->where('poll_id', $poll->id)
                ->where('guest_id', $viewer->id)
                ->value('poll_option_id');
        }

        return Serializers::pollShape($poll, $myVote === null ? null : (int) $myVote);
    }

    // ------------------------------------------------------------------
    // Validation helpers
    // ------------------------------------------------------------------

    private function cleanQuestion(mixed $question): string
    {
        $question = is_string($question) ? trim($question) : '';
        if ($question === '') {
            throw PollException::make('poll_question_required');
        }

        return $question;
    }

    /**
     * Option texts for create: strings (or ['text' => ...] arrays), trimmed,
     * empties dropped, at least two required.
     *
     * @param  array<int, mixed>  $options
     * @return array<int, string>
     */
    private function cleanOptionTexts(array $options): array
    {
        return array_map(
            fn (array $entry) => $entry['text'],
            $this->cleanOptionEntries($options),
        );
    }

    /**
     * Normalized option entries: [{id: ?int, text: string}] in the order
     * given, empties dropped, at least two required.
     *
     * @param  array<int, mixed>  $options
     * @return array<int, array{id: ?int, text: string}>
     */
    private function cleanOptionEntries(array $options): array
    {
        $entries = [];
        foreach ($options as $option) {
            if (is_string($option)) {
                $entry = ['id' => null, 'text' => trim($option)];
            } elseif (is_array($option)) {
                $id = $option['id'] ?? null;
                $entry = [
                    'id' => is_numeric($id) ? (int) $id : null,
                    'text' => is_string($option['text'] ?? null) ? trim($option['text']) : '',
                ];
            } else {
                continue;
            }

            if ($entry['text'] !== '') {
                $entries[] = $entry;
            }
        }

        if (count($entries) < 2) {
            throw PollException::make('poll_needs_options');
        }

        return $entries;
    }
}
