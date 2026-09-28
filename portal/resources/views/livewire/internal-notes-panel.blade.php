<?php

use Livewire\Volt\Component;

new class extends Component
{
    public int $ticketId;
    public string $currentUserName;
    public string $currentUserRole;

    /**
     * Initial internal-note history passed in by the parent view as
     * a plain array of associative arrays. The Alpine wrapper
     * handles new notes from this point on, so the component itself
     * doesn't need to re-query.
     */
    public array $notes = [];

    public string $body = '';

    public function mount(
        int $ticketId,
        string $currentUserName,
        string $currentUserRole,
        array $notes = [],
    ): void {
        $this->ticketId        = $ticketId;
        $this->currentUserName = $currentUserName;
        $this->currentUserRole = $currentUserRole;
        $this->notes           = $notes;
    }

    public function submit(): void
    {
        $body = trim($this->body);
        if ($body === '') {
            return;
        }

        $request = request();
        $request->merge(['body' => $body]);

        app(\App\Http\Controllers\Tsp\InternalNoteController::class)
            ->store($request, (string) $this->ticketId);

        // Skip render: controller already persisted + broadcast on
        // Pusher. Alpine's `note-sent-ack` listener clears the input.
        $this->skipRender();
        $this->dispatch('note-sent-ack');
    }
}; ?>

<div
    x-data="internalNotesPanel({
        ticketId: @js($ticketId),
        currentUserName: @js($currentUserName),
        currentUserRole: @js($currentUserRole),
    })"
    x-init="init()"
    class="bg-base-100 shadow sm:rounded-2xl flex flex-col h-[640px] border border-base-300/70"
>
    <div class="px-6 py-4 border-b border-base-300/70 flex items-center justify-between">
        <h3 class="text-[15px] font-semibold text-base-content">
            Internal notes
        </h3>
        <span class="flex items-center gap-1.5 text-xs text-base-content/60">
            <span
                x-show="pusherConnected"
                x-cloak
                class="inline-block h-2 w-2 rounded-full bg-success"
            ></span>
            <span x-show="pusherConnected" x-cloak>Live</span>
        </span>
    </div>
    <p class="px-6 pt-2 text-xs text-warning bg-warning/5 border-b border-warning/15">
        Visible to {{ $currentUserRole === 'admin' ? 'admin' : 'TSP' }} only. Never shown to the customer.
    </p>

    <div
        class="flex-1 overflow-y-auto px-6 py-4 space-y-3"
        x-ref="log"
    >
        <template x-for="note in notes" :key="note.id">
            <article class="rounded-xl border border-warning/25 bg-warning/5 px-4 py-3">
                <header class="flex items-center justify-between text-xs text-base-content/60">
                    <span class="font-medium text-base-content" x-text="note.author_name"></span>
                    <time
                        :datetime="note.created_at"
                        x-text="formatTime(note.created_at)"
                    ></time>
                </header>
                <p class="mt-1 text-sm text-base-content whitespace-pre-wrap break-words" x-text="note.body"></p>
                <footer class="mt-2 text-[10px] uppercase tracking-wide text-warning" x-text="note.author_role"></footer>
            </article>
        </template>

        <p
            x-show="notes.length === 0"
            class="text-sm text-base-content/60 italic"
        >
            No internal notes yet. Add the first one below — only TSP / admin can see this.
        </p>
    </div>

    <form
        wire:submit.prevent="submit"
        class="px-6 py-4 border-t border-base-300/70"
    >
        <label class="sr-only" for="internal-note-body">Add an internal note</label>
        <textarea
            id="internal-note-body"
            x-ref="input"
            wire:model="body"
            rows="3"
            maxlength="5000"
            placeholder="Add an internal note (visible to TSP only)..."
            class="textarea textarea-bordered block w-full text-sm bg-base-200/50 focus:outline-none focus:border-warning"
        ></textarea>
        <div class="mt-2 flex justify-end">
            <button
                type="submit"
                class="btn btn-warning btn-sm gap-1"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add note
            </button>
        </div>
    </form>
</div>
