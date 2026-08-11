{{-- =============================================================
     TSR — Technical Service Report (TSP-facing)
     =============================================================
     Owned by: App\Livewire\Tsp\Tickets\CreateServiceReport
     All form state lives in the Livewire component; this view is
     a presentational layer only.

     UX notes:
       * The top "sticky bar" actually uses position: sticky so the
         TSP always sees the ticket #, status, and Save button.
       * Required fields are marked with a red * and the form
         scrolls to the first error after a failed submit.
       * Total minutes is shown as a friendly "Xh Ym" string.
       * Co-TSPs are entered as comma-separated ids with a live
         preview of how many were parsed.
       * The whole form degrades gracefully: if Alpine isn't ready
         the server-rendered HTML still works (Livewire's wire:model
         + @push scripts handle the rest).
     ============================================================= --}}

@php
    // Status -> DaisyUI semantic tokens. Centralized here so the
    // pill picker (step 2) and the sync pill agree on the mapping.
    $statusTones = [
        'open'        => ['tone' => 'ghost',   'dot' => 'bg-base-content/40', 'label' => 'Open'],
        'in_progress' => ['tone' => 'primary', 'dot' => 'bg-primary',         'label' => 'In progress'],
        'pending'     => ['tone' => 'warning', 'dot' => 'bg-warning',         'label' => 'Pending'],
        'escalated'   => ['tone' => 'error',   'dot' => 'bg-error',           'label' => 'Escalated'],
        'completed'   => ['tone' => 'success', 'dot' => 'bg-success',         'label' => 'Completed'],
    ];
    $currentTone = $statusTones[$serviceStatus] ?? $statusTones['in_progress'];

    // Friendly duration ("3h 15m" / "45m" / "0m").
    $duration = $totalMinutes > 0
        ? sprintf('%dh %dm', intdiv($totalMinutes, 60), $totalMinutes % 60)
        : '0m';

    // Pre-fill TSP name/email from the auth user so the TSP
    // doesn't have to type it for every report.
    $tspName  = auth()->user()?->name  ?? '';
    $tspEmail = auth()->user()?->email ?? '';

    $stepLabels = [
        1 => 'Equipment & time',
        2 => 'Work details',
        3 => 'Signatures',
    ];

    $narratives = [
        ['wire' => 'problemAndConcerns', 'label' => 'What was the problem?', 'icon' => 'alert',      'required' => true,  'ph' => "What issue did the customer report? What wasn't working?",     'hint' => 'Start with the symptom in the customer\'s words.'],
        ['wire' => 'jobDone',            'label' => 'What did you do?',      'icon' => 'check',      'required' => true,  'ph' => 'Describe the work you performed to resolve the issue',     'hint' => 'Step-by-step actions taken, calibration, settings changed.'],
        ['wire' => 'partsReplaced',      'label' => 'Parts replaced',        'icon' => 'cog',        'required' => false, 'ph' => 'List any parts used (include part numbers if available)', 'hint' => 'Optional. Add part numbers and quantities.'],
        ['wire' => 'recommendation',     'label' => 'Recommendations',       'icon' => 'lightbulb',  'required' => false, 'ph' => 'Any follow-up work needed? Suggestions for the customer?', 'hint' => 'Preventive care, training needs, replacement timeline.'],
        ['wire' => 'remarks',            'label' => 'Additional notes',      'icon' => 'note',       'required' => false, 'ph' => 'Anything else we should know about this service call',     'hint' => 'Optional. Free-form context for the office.'],
    ];
@endphp

<div
    class="tsr-form"
    x-data="tsrForm()"
    x-init="init()"
>
    {{-- ───────────────────── Header ───────────────────── --}}
    <div class="px-5 py-4 border-b border-base-300/70 flex flex-wrap items-center gap-3 sticky top-0 bg-base-100 z-10">
        <div class="flex items-center gap-2 min-w-0">
            <span class="w-8 h-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-base-content/60">Service report</div>
                <div class="text-base font-semibold text-base-content truncate">Ticket #{{ $ticketNumber }}</div>
            </div>
        </div>
        <div class="flex-1"></div>
        <span
            class="badge badge-sm gap-1 font-medium"
            :class="{
                'badge-error': error > 0,
                'badge-info': syncing > 0,
                'badge-warning': pending > 0,
                'badge-success': synced > 0 && error === 0 && pending === 0,
                'badge-ghost': synced === 0 && pending === 0 && syncing === 0 && error === 0,
            }"
            :title="syncPillTitle"
        >
            <span x-text="syncPillIcon" aria-hidden="true"></span>
            <span x-text="syncPillLabel"></span>
        </span>
        <span class="inline-flex items-center gap-1.5 text-[11px] text-base-content/60" :title="online ? 'Connected — changes sync automatically' : 'No internet — changes are saved locally'">
            <span class="w-1.5 h-1.5 rounded-full" :class="online ? 'bg-success' : 'bg-warning'"></span>
            <span x-text="online ? 'Online' : 'Offline'">Online</span>
        </span>
        {{-- Manual "Save draft" — explicit, discoverable way to save
             without submitting. Lives in the top header so it sits
             inside the x-data scope (the bottom sticky bar is
             re-parented out of the form by Livewire's morph, so its
             Alpine directives don't resolve). --}}
        <button
            type="button"
            class="btn btn-ghost btn-sm gap-1.5"
            @click="saveDraftManual()"
            :title="draftSavedAt ? 'Draft saved just now' : 'Save your progress as a draft on this device'"
        >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <span x-text="draftSavedAt ? 'Saved ✓' : 'Save draft'"></span>
        </button>
    </div>

    {{-- ───────────────────── Stepper ───────────────────── --}}
    <div class="px-5 py-4 border-b border-base-300/70 bg-base-200/40">
        <ol class="flex items-center w-full" role="list">
            @foreach ($stepLabels as $num => $label)
                @php $isLast = $num === count($stepLabels); @endphp
                <li class="flex items-center flex-1 min-w-0" x-show="currentStep >= {{ $num - 1 }} && currentStep <= {{ $num + 1 }}">
                    <button
                        type="button"
                        class="flex items-center gap-2 min-w-0 group focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded-md"
                        x-on:click="goToStep({{ $num }})"
                        :aria-current="currentStep === {{ $num }} ? 'step' : null"
                    >
                        <span
                            class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold border-2 transition shrink-0"
                            :class="{
                                'bg-primary border-primary text-primary-content': currentStep === {{ $num }},
                                'bg-secondary border-secondary text-secondary-content': currentStep > {{ $num }},
                                'bg-base-100 border-base-300 text-base-content/50': currentStep < {{ $num }},
                            }"
                        >
                            <template x-if="currentStep > {{ $num }}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5">
                                    <path fill-rule="evenodd" d="M16.704 5.296a1 1 0 010 1.408l-7.997 8a1 1 0 01-1.408 0l-3.999-4a1 1 0 011.408-1.408L8 12.59l7.296-7.294a1 1 0 011.408 0z" clip-rule="evenodd" />
                                </svg>
                            </template>
                            <template x-if="currentStep <= {{ $num }}">
                                <span>{{ $num }}</span>
                            </template>
                        </span>
                        <span
                            class="text-xs font-medium truncate hidden sm:inline"
                            :class="currentStep === {{ $num }} ? 'text-primary' : (currentStep > {{ $num }} ? 'text-base-content' : 'text-base-content/50')"
                        >{{ $label }}</span>
                    </button>
                    @if (! $isLast)
                        <div class="flex-1 h-0.5 mx-2 rounded" :class="currentStep > {{ $num }} ? 'bg-secondary' : 'bg-base-300'"></div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    {{-- ───────────────────── Flash messages ───────────────────── --}}
    @if ($lastError)
        <div class="mx-5 mt-4 alert alert-error" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z"/></svg>
            <div class="text-sm"><strong>Something went wrong:</strong> {{ $lastError }}</div>
        </div>
    @endif

    @if (session('tsr.saved'))
        <div class="mx-5 mt-4 alert alert-success" role="status" x-data x-init="queueDrain()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <div class="text-sm">
                <strong>Report saved!</strong>
                <span x-show="online" x-cloak>Syncing to Monday.com now.</span>
                <span x-show="!online" x-cloak>Will sync when you're back online.</span>
            </div>
        </div>
    @endif

    {{-- ───────────────────── Draft autosave status ───────────────────── --}}
    <div
        class="mx-5 mt-4 alert alert-info"
        role="status"
        x-show="hasDraft && !_draftRestored"
        x-cloak
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        <div class="flex-1 text-sm">
            <strong>Unfinished report found.</strong>
            <span class="text-base-content/80">We're restoring your previous work so you can pick up where you left off.</span>
        </div>
        <button type="button" class="btn btn-ghost btn-xs" @click="discardDraft()">Start fresh</button>
    </div>

    <form wire:submit.prevent="submit" novalidate>
        <div class="px-5 py-5 space-y-6">

            {{-- ═════════════════════════════════════════════════════
                 STEP 1 — Equipment & Service Time
                 ═════════════════════════════════════════════════════ --}}
            <section x-show="currentStep === 1" x-transition.opacity>
                <div class="mb-4">
                    <h3 class="text-base font-semibold text-base-content">Equipment &amp; service time</h3>
                    <p class="text-sm text-base-content/60 mt-0.5">What did you service, and for how long?</p>
                </div>

                <div class="rounded-lg border border-base-300 bg-base-100 p-4 mb-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-6 h-6 rounded-md bg-primary/10 text-primary flex items-center justify-center" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        </span>
                        <h4 class="text-sm font-semibold text-base-content">Equipment details</h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Machine / system serial number</span></div>
                            <input
                                type="text"
                                wire:model.blur="machineSystemSerialNumber"
                                class="input input-bordered input-sm w-full"
                                placeholder="e.g. SN-2024-00123"
                                autocomplete="off"
                            />
                        </label>
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Software version</span></div>
                            <input
                                type="text"
                                wire:model.blur="softwareVersionNo"
                                class="input input-bordered input-sm w-full"
                                placeholder="e.g. v3.2.1"
                                autocomplete="off"
                            />
                        </label>
                    </div>
                </div>

                <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-md bg-accent/10 text-accent flex items-center justify-center" aria-hidden="true">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <h4 class="text-sm font-semibold text-base-content">Service time</h4>
                        </div>
                        <span class="badge badge-ghost gap-1 font-mono text-xs" title="Total service duration">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V8a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 7.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                            {{ $duration }}
                        </span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Login time <span class="text-[10px] font-normal text-base-content/50 ml-1">on-site sign-in</span></span></div>
                            <input
                                type="datetime-local"
                                wire:model.live="logInDate"
                                class="input input-bordered input-sm w-full"
                            />
                        </label>
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Service start <span class="text-error">*</span><span class="text-[10px] font-normal text-base-content/50 ml-1">arrival at the equipment</span></span></div>
                            <input
                                type="datetime-local"
                                wire:model.live="serviceStartDateTime"
                                class="input input-bordered input-sm w-full"
                                required
                            />
                        </label>
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Service end <span class="text-error">*</span><span class="text-[10px] font-normal text-base-content/50 ml-1">work completed</span></span></div>
                            <input
                                type="datetime-local"
                                wire:model.live="serviceEndDateTime"
                                class="input input-bordered input-sm w-full"
                                required
                            />
                        </label>
                        <label class="form-control w-full">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Logout time <span class="text-[10px] font-normal text-base-content/50 ml-1">on-site sign-out</span></span></div>
                            <input
                                type="datetime-local"
                                wire:model.live="logOutDate"
                                class="input input-bordered input-sm w-full"
                            />
                        </label>
                    </div>
                    <p class="text-[11px] text-base-content/60 mt-2 flex items-center gap-1.5"
                       x-data="tsrTimeAutofill()"
                       x-show="! $wire.get('serviceStartDateTime')"
                       x-cloak>
                        <span aria-hidden="true">💡</span>
                        Tip: We'll auto-fill any blank timestamp with the current time if you leave it empty.
                    </p>
                </div>
            </section>

            {{-- ═════════════════════════════════════════════════════
                 STEP 2 — Status & Work Details
                 ═════════════════════════════════════════════════════ --}}
            <section x-show="currentStep === 2" x-transition.opacity>
                <div class="mb-4">
                    <h3 class="text-base font-semibold text-base-content">Work details</h3>
                    <p class="text-sm text-base-content/60 mt-0.5">Describe the problem and what you did to fix it.</p>
                </div>

                <div class="rounded-lg border border-base-300 bg-base-100 p-4 mb-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-6 h-6 rounded-md bg-secondary/10 text-secondary flex items-center justify-center" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <h4 class="text-sm font-semibold text-base-content">Service status</h4>
                    </div>
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Service status">
                        @foreach ($statusTones as $value => $meta)
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="(($wire.get('serviceStatus') || 'in_progress') === '{{ $value }}').toString()"
                                wire:click="$set('serviceStatus', '{{ $value }}')"
                                class="btn btn-sm gap-1.5 normal-case font-medium"
                                :class="(($wire.get('serviceStatus') || 'in_progress') === '{{ $value }}') ? 'btn-{{ $meta['tone'] }} btn-active' : 'btn-ghost border border-base-300'"
                            >
                                <span class="w-1.5 h-1.5 rounded-full {{ $meta['dot'] }}" aria-hidden="true"></span>
                                {{ $meta['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach ($narratives as $row)
                        <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <label class="flex items-center gap-2 text-sm font-semibold text-base-content">
                                    @switch($row['icon'])
                                        @case('alert')
                                            <svg class="w-4 h-4 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z"/></svg>
                                            @break
                                        @case('check')
                                            <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            @break
                                        @case('cog')
                                            <svg class="w-4 h-4 text-base-content/60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            @break
                                        @case('lightbulb')
                                            <svg class="w-4 h-4 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                            @break
                                        @case('note')
                                            <svg class="w-4 h-4 text-base-content/60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            @break
                                    @endswitch
                                    <span>{{ $row['label'] }}</span>
                                    @if ($row['required']) <span class="text-error">*</span> @endif
                                </label>
                                <span class="text-[10px] font-medium text-base-content/50 tabular-nums"
                                      x-data="{ count: 0 }"
                                      x-init="count = ($wire.get('{{ $row['wire'] }}') || '').length"
                                      @input.window="count = ($wire.get('{{ $row['wire'] }}') || '').length">
                                    <span x-text="count"></span>/5000
                                </span>
                            </div>
                            <textarea
                                wire:model.live="{{ $row['wire'] }}"
                                class="textarea textarea-bordered w-full text-sm leading-relaxed"
                                rows="3"
                                maxlength="5000"
                                placeholder="{{ $row['ph'] }}"
                                @if($row['required']) required @endif
                            ></textarea>
                            <div class="h-0.5 mt-2 bg-base-200 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all bg-primary/60"
                                    :class="(($wire.get('{{ $row['wire'] }}') || '').length) > 4500 ? 'bg-error' : 'bg-primary/60'"
                                    :style="`width: ${Math.min(100, ((($wire.get('{{ $row['wire'] }}') || '').length) / 5000) * 100)}%`"
                                ></div>
                            </div>
                            @if (! empty($row['hint']))
                                <p class="text-[11px] text-base-content/55 mt-1.5">{{ $row['hint'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- ═════════════════════════════════════════════════════
                 STEP 3 — Signatures
                 ═════════════════════════════════════════════════════ --}}
            <section x-show="currentStep === 3" x-transition.opacity>
                <div class="mb-4">
                    <h3 class="text-base font-semibold text-base-content">Signatures</h3>
                    <p class="text-sm text-base-content/60 mt-0.5">Collect signatures from everyone involved in this service call.</p>
                </div>

                <div class="space-y-3">
                    {{-- TSP signature --}}
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-md bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">YOU</span>
                            <div>
                                <div class="text-sm font-semibold text-base-content">Field service engineer</div>
                                <div class="text-[11px] text-base-content/55">That's you, signing off on the work you performed.</div>
                            </div>
                        </div>
                        <label class="form-control w-full mb-2">
                            <div class="label py-1"><span class="label-text text-sm font-medium">Your name</span></div>
                            <input
                                type="text"
                                wire:model="tspSignatureName"
                                class="input input-bordered input-sm w-full"
                                placeholder="{{ $tspName }}"
                            />
                        </label>
                        <x-signature-pad name="tspSignatureDataUrl" :width="500" :height="120" />
                    </div>

                    {{-- Customer signature --}}
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-md bg-secondary/10 text-secondary flex items-center justify-center text-xs font-bold">CST</span>
                            <div>
                                <div class="text-sm font-semibold text-base-content">Customer</div>
                                <div class="text-[11px] text-base-content/55">The hospital contact who authorized the work.</div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-2">
                            <label class="form-control w-full">
                                <div class="label py-1"><span class="label-text text-sm font-medium">Customer name</span></div>
                                <input
                                    type="text"
                                    wire:model="customerName"
                                    class="input input-bordered input-sm w-full"
                                    placeholder="Customer's full name"
                                />
                            </label>
                            <label class="form-control w-full">
                                <div class="label py-1"><span class="label-text text-sm font-medium">Customer email</span></div>
                                <input
                                    type="email"
                                    wire:model="customerEmail"
                                    class="input input-bordered input-sm w-full"
                                    placeholder="customer@example.com"
                                />
                            </label>
                        </div>
                        <x-signature-pad name="customerSignatureDataUrl" :width="500" :height="120" />
                    </div>

                    {{-- BIOMED signature --}}
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-md bg-accent/10 text-accent flex items-center justify-center text-xs font-bold">BMD</span>
                            <div>
                                <div class="text-sm font-semibold text-base-content">BIOMED</div>
                                <div class="text-[11px] text-base-content/55">The on-site biomed contact, if any.</div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-2">
                            <label class="form-control w-full">
                                <div class="label py-1"><span class="label-text text-sm font-medium">Biomed name</span></div>
                                <input
                                    type="text"
                                    wire:model="biomedName"
                                    class="input input-bordered input-sm w-full"
                                    placeholder="Biomed contact's full name"
                                />
                            </label>
                            <label class="form-control w-full">
                                <div class="label py-1"><span class="label-text text-sm font-medium">Biomed email</span></div>
                                <input
                                    type="email"
                                    wire:model="biomedEmail"
                                    class="input input-bordered input-sm w-full"
                                    placeholder="biomed@example.com"
                                />
                            </label>
                        </div>
                        <x-signature-pad name="biomedSignatureDataUrl" :width="500" :height="120" />
                    </div>
                </div>

                {{-- Team members (optional) — multi-select dropdown of other TSPs --}}
                @php
                    // Render-time hint for initial paint; Alpine reads live state via $wire.
                    $availableTspsJson = json_encode($availableTsps, JSON_THROW_ON_ERROR);
                @endphp
                <div
                    class="mt-4 rounded-lg border border-base-300 bg-base-100 p-4"
                    x-data='{
                        search: "",
                        open: false,
                        // Snapshotted once; availableTsps is a static list of DB users
                        // and does not change during a session.
                        allTsps: {{ $availableTspsJson }},
                        // Internal mirror of the selected TSPs. We keep a local copy
                        // (keyed off monday_id) so Alpine x-for can diff cleanly;
                        // the wire:$entangle mirror keeps it in sync with Livewire.
                        selectedTsps: [],
                        init() {
                            this.recomputeSelected();
                            // Re-mirror whenever Livewire mutates tspWorkWith.
                            this.$watch("$wire.tspWorkWith", () => this.recomputeSelected());
                        },
                        recomputeSelected() {
                            const ids = (this.$wire.get("tspWorkWith") || []).map(String);
                            const next = ids
                                .map(id => this.allTsps.find(t => t && String(t.monday_id) === id))
                                .filter(Boolean);
                            this.selectedTsps = next;
                        },
                        get ids() {
                            return (this.$wire.get("tspWorkWith") || []).map(String);
                        },
                        get available() {
                            const term = this.search.trim().toLowerCase();
                            if (!term) return this.allTsps;
                            return this.allTsps.filter(t =>
                                (t.name || "").toLowerCase().includes(term)
                                || (t.role || "").toLowerCase().includes(term)
                                || String(t.monday_id || "").includes(term)
                            );
                        },
                        get count() { return this.selectedTsps.length; },
                        isSelected(id) { return this.ids.includes(String(id)); },
                    }'
                >
                    {{-- Header --}}
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-6 h-6 rounded-md bg-base-200 text-base-content/70 flex items-center justify-center" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-2a4 4 0 11-8 0 4 4 0 018 0zm6 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        </span>
                        <h4 class="text-sm font-semibold text-base-content">
                            Team members
                            <span class="text-xs font-normal text-base-content/55 ml-1">optional</span>
                        </h4>
                        <span
                            class="ml-auto text-[11px]"
                            :class="count > 0 ? 'text-primary font-semibold' : 'text-base-content/55'"
                        >
                            <span x-text="count"></span> member<span x-show="count !== 1">s</span> added
                        </span>
                    </div>

                    <p class="text-[11px] text-base-content/55 mb-3">
                        Did other technicians help with this service? Pick them from the list below. Leave empty if you worked alone.
                    </p>

                    {{-- Selected chips --}}
                    <div
                        class="flex flex-wrap gap-1.5 mb-2 min-h-[1.75rem]"
                        x-show="count > 0"
                        x-cloak
                    >
                        <template x-for="t in selectedTsps" :key="t.monday_id">
                            <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 text-primary border border-primary/30 pl-2.5 pr-1 py-0.5 text-xs font-medium">
                                <span x-text="t.name || ('#' + t.monday_id)"></span>
                                <span class="text-primary/60 text-[10px] uppercase tracking-wide" x-text="t.role"></span>
                                <button
                                    type="button"
                                    class="ml-0.5 w-4 h-4 rounded-full hover:bg-primary/20 flex items-center justify-center"
                                    :aria-label="'Remove ' + (t.name || t.monday_id)"
                                    @click="$wire.removeTsp(String(t.monday_id))"
                                >
                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </span>
                        </template>
                    </div>

                    {{-- Search + dropdown trigger --}}
                    <div
                        class="relative"
                        @click.outside="open = false"
                    >
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <svg class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                                <input
                                    type="text"
                                    x-model="search"
                                    @focus="open = true"
                                    placeholder="Search technicians by name, role, or Monday ID…"
                                    class="input input-bordered input-sm w-full pl-7"
                                    autocomplete="off"
                                />
                            </div>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm gap-1"
                                @click="open = !open"
                                :aria-expanded="open ? 'true' : 'false'"
                            >
                                <span x-text="open ? 'Hide' : 'Show'"></span>
                                <svg class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                        </div>

                        {{-- Dropdown list --}}
                        <div
                            x-show="open"
                            x-cloak
                            x-transition.opacity.duration.150ms
                            class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-base-300 bg-base-100 shadow-lg"
                        >
                            <template x-if="available.length === 0">
                                <div class="px-3 py-4 text-center text-xs text-base-content/55">
                                    No matching technicians.
                                </div>
                            </template>
                            <template x-for="t in available" :key="t.monday_id">
                                <button
                                    type="button"
                                    class="w-full flex items-center gap-2 px-3 py-2 text-left text-sm hover:bg-base-200/60 focus:bg-base-200/60 focus:outline-none transition-colors"
                                    :class="isSelected(t.monday_id) ? 'bg-primary/5' : ''"
                                    @click="
                                        const mid = String(t.monday_id);
                                        $wire.toggleTsp(mid);
                                        // keep open so users can pick several in a row
                                    "
                                >
                                    <span
                                        class="w-4 h-4 rounded border flex items-center justify-center flex-shrink-0"
                                        :class="isSelected(t.monday_id) ? 'bg-primary border-primary text-primary-content' : 'border-base-300 bg-base-100'"
                                    >
                                        <svg x-show="isSelected(t.monday_id)" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                    <span class="flex-1 min-w-0">
                                        <span class="block truncate font-medium" x-text="t.name || ('#' + t.monday_id)"></span>
                                        <span class="block text-[11px] text-base-content/55">
                                            <span class="uppercase tracking-wide" x-text="t.role"></span>
                                            <template x-if="t.monday_id">
                                                <span> · Mon #<span x-text="t.monday_id"></span></span>
                                            </template>
                                        </span>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Hidden shim: keeps $tspWorkWithCsv populated for any callers that
                         still read it (e.g. legacy test fixtures). Bound with wire:model
                         so server state matches the dropdown. --}}
                    <input type="hidden" wire:model="tspWorkWithCsv" />
                </div>
            </section>

            {{-- Review & Submit removed — submit is now on the Signatures step --}}

        </div>

        {{-- ───────────────────── Sticky footer (nav + sync) ───────────────────── --}}
        <div class="sticky bottom-0 bg-base-100 border-t border-base-300/70 px-5 py-3 flex flex-wrap items-center gap-2 z-10">
            <button
                type="button"
                class="btn btn-ghost btn-sm gap-1.5"
                x-show="currentStep > 1"
                x-cloak
                @click="goToStep(currentStep - 1)"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </button>

            <div class="flex-1 flex items-center gap-2 text-[11px] text-base-content/55 min-w-0">
                <span x-show="lastSyncedAt" x-cloak>
                    Last synced: <span x-text="lastSyncedHuman"></span>
                </span>
                <span x-show="_draftAvailable" x-cloak class="inline-flex items-center gap-1">
                    <span aria-hidden="true">💾</span>
                    Auto-saving your work
                </span>
            </div>

            <button
                type="button"
                class="btn btn-ghost btn-sm gap-1.5"
                x-show="(pending > 0 || error > 0) && !syncInFlight"
                x-cloak
                @click="manualSync()"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <span x-text="error > 0 ? 'Retry sync' : 'Sync to Monday'"></span>
            </button>

            <button
                type="button"
                class="btn btn-primary btn-sm gap-1.5"
                x-show="currentStep < 3"
                x-cloak
                @click="goToStep(currentStep + 1)"
            >
                Next
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>

            <button
                type="submit"
                class="btn btn-primary btn-sm gap-1.5"
                x-show="currentStep === 3"
                x-cloak
                wire:loading.attr="disabled"
                wire:target="submit"
            >
                <span wire:loading.remove wire:target="submit">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Submit report
                </span>
                <span wire:loading wire:target="submit" class="inline-flex items-center gap-1.5">
                    <span class="loading loading-spinner loading-xs"></span>
                    Submitting…
                </span>
            </button>
        </div>
    </form>

    @once
        @push('scripts')
            {{-- offline-tsr.js lives in resources/js/portal/; we publish
                 a copy of the same file under public/js/portal/ during
                 deploy so it can be served as a static asset. If it is
                 missing we still want the form to function, so we wrap
                 the call in a try/catch. --}}
            <script src="{{ asset('js/portal/offline-tsr.js') }}" defer></script>
            <script>
                window.__tsrLocalId = @json($localId);
                window.__tsrTicketNumber = @json($ticketNumber);
                window.__tspName  = @json($tspName);
                window.__tspEmail = @json($tspEmail);
                window.__tsrSyncUrl   = @json(route('tsp.tickets.tsr.sync',   ['id' => $ticketNumber]));
                window.__tsrStatusUrl = @json(route('tsp.tickets.tsr.status', ['id' => $ticketNumber]));
            </script>
            <style>
                /* The form lives inside a Breeze x-modal so we
                   need a vertical scroll boundary that is the modal
                   panel (not the window). The modal panel already
                   has max-h-[calc(100vh-4rem)] and overflow-y-auto,
                   so the page just works as-is - we only need to
                   add a touch of margin so the inner steps breathe. */
                .tsr-form { margin: 0 -.25rem; }
                .tsr-form .signature-pad__canvas {
                    background: #fff;
                    border-color: oklch(var(--bc) / 0.2);
                }
                [x-cloak] { display: none !important; }
            </style>
            <script>
                // ----------------------------------------------------------------
                //  tsrForm — Alpine component wrapping the Livewire TSR form.
                //  Owns:
                //    * online / offline detection + a manual "force offline"
                //      toggle for testing
                //    * per-row sync state (pending / syncing / synced / error)
                //      polled from the server every 5s
                //    * a "Sync to Monday" button that POSTs the drainer
                //      endpoint, plus an auto-drain on the `online` event
                //    * the friendly "X queued / Y synced" pill on the
                //      sticky bar
                //    * smooth-scroll to the first error on submit failure
                // ----------------------------------------------------------------
                window.tsrForm = function () {
                    return {
                        // Wizard navigation
                        currentStep: 1,
                        totalSteps:  3,
                        goToStep(n) {
                            if (n < 1 || n > this.totalSteps) return;
                            this.currentStep = n;
                            // Scroll to top of form
                            this.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        },

                        // Connection state
                        online: (typeof navigator !== 'undefined' ? navigator.onLine : true),
                        forcedOffline: false,

                        // Sync state (counts per state for this ticket)
                        pending: 0,
                        syncing: 0,
                        synced:  0,
                        error:   0,
                        lastError: null,
                        lastSyncedAt: null,
                        syncInFlight: false,
                        _statusTimer: null,
                        _initialized: false,

                        // Draft autosave (localStorage). Saves a snapshot
                        // of the form on every meaningful change so the
                        // user can close the browser, lose connection, or
                        // accidentally refresh without losing their work.
                        _draftSaveTimer: null,
                        _draftRestored: false,
                        _draftDiscarding: false,
                        _draftUserTouched: false,
                        _hydrating: false,

                        // Manual "Save draft" feedback. The button in
                        // the top header calls saveDraftManual() which
                        // writes a snapshot to localStorage and flashes
                        // the button text "Saved ✓" for 2 seconds.
                        draftSavedAt: null,
                        _draftManualTimer: null,

                        // ─── Lifecycle ───
                        init() {
                            if (this._initialized) return;
                            this._initialized = true;

                            // Wire online/offline listeners.
                            const update = () => {
                                if (! this.forcedOffline) {
                                    this.online = navigator.onLine;
                                }
                                if (this.online) {
                                    // We just came online (or started
                                    // online). Kick off a drain so any
                                    // queued TSRs for this ticket get
                                    // pushed to Monday right away. The
                                    // drain is a no-op when there's
                                    // nothing pending — see queueDrain().
                                    this.queueDrain();
                                }
                            };
                            window.addEventListener('online',  update);
                            window.addEventListener('offline', update);

                            // Initial status fetch, then poll every 5s.
                            // The poll ALSO triggers a drain whenever
                            // it sees pending/error work and we're
                            // online — that catches the case where the
                            // tab was open across a connection drop and
                            // the browser didn't fire an `online` event
                            // (some browsers don't when the network
                            // restores from sleep / VPN reconnect).
                            this.fetchStatus();
                            this._statusTimer = setInterval(() => {
                                this.fetchStatus().then(() => {
                                    if (this.online
                                        && ! this.syncInFlight
                                        && (this.pending > 0 || this.error > 0)
                                    ) {
                                        this.queueDrain();
                                    }
                                });
                            }, 5000);

                            // Stop the timer when the form is torn down.
                            this.$el.addEventListener('tsr.teardown', () => {
                                if (this._statusTimer) clearInterval(this._statusTimer);
                            });

                            // ── Draft autosave wiring ──
                            // Hydrate from localStorage (if any) AFTER
                            // Livewire has had a chance to mount and
                            // expose the wire id on the root.
                            //
                            // Key the draft on the TICKET NUMBER, not
                            // the random localId. localId is regenerated
                            // by the server on every request, so a
                            // key derived from it would orphan the
                            // draft across reloads. The ticket number
                            // is stable per ticket and unique enough
                            // for this purpose.
                            this._draftKey = 'tsr.draft.' + (window.__tsrTicketNumber || window.__tsrLocalId || 'unknown');

                            // Hydrate IMMEDIATELY (synchronously) so
                            // the form is populated before any save
                            // listener can fire. If we waited for
                            // `livewire:init` or a setTimeout, the
                            // Livewire `livewire:updated` event would
                            // land first and clobber the stored draft
                            // with the (empty) current form values.
                            this._hydrateFromDraft();

                            // Save on any Livewire property update
                            // (debounced). The 'livewire:updated' event
                            // fires after Livewire commits a set() call,
                            // which covers every wire:model roundtrip.
                            window.addEventListener('livewire:updated', () => this._scheduleDraftSave());

                            // Fallback: also listen for DOM input/change
                            // events on the form root. The form is
                            // wrapped in `wire:ignore.self`, so
                            // wire:model on text inputs is intentionally
                            // inert. The `livewire:updated` event
                            // therefore does not fire when the user
                            // types, so we also hook the native input
                            // event for any input/textarea/select inside
                            // the form.
                            const root = this.$el;
                            if (root) {
                                // The `input`/`change` events are
                                // fired by the user actually editing
                                // the form (not by Livewire's initial
                                // mount), so they're a safe signal to
                                // mark the draft as "user-touched" and
                                // enable autosave. Livewire's
                                // `livewire:updated` event below is
                                // NOT used to mark touched, because
                                // it also fires on initial mount.
                                //
                                // We also ignore events fired while
                                // we're re-hydrating the form, so the
                                // programmatic `el.dispatchEvent(...)`
                                // calls in `_hydrateFromDraft` do NOT
                                // mark the form as user-touched
                                // (otherwise a freshly-restored draft
                                // would overwrite itself on the next
                                // autosave tick).
                                const markTouched = () => {
                                    if (this._hydrating) return;
                                    this._draftUserTouched = true;
                                    this._scheduleDraftSave();
                                };
                                root.addEventListener('input',  markTouched);
                                root.addEventListener('change', markTouched);
                            }

                            // Save on signature-pad commits.
                            window.addEventListener('tsr.signature-committed', () => this._scheduleDraftSave());

                            // Final save on page hide (covers refresh
                            // and tab close). navigator.sendBeacon is
                            // not appropriate here because the data is
                            // already in localStorage; we just make
                            // sure the timer doesn't swallow it.
                            // The save only runs if the user has
                            // actually touched the form — otherwise
                            // a reload right after page load would
                            // overwrite a stored draft with the
                            // (empty) current form values.
                            window.addEventListener('pagehide', () => {
                                if (! this._draftUserTouched) return;
                                if (this._draftSaveTimer) {
                                    clearTimeout(this._draftSaveTimer);
                                    this._draftSaveTimer = null;
                                }
                                this._saveDraftNow();
                            });

                            // Clear the draft once the Livewire submit
                            // succeeds (the component dispatches
                            // 'tsr.saved' on success).
                            window.addEventListener('tsr.saved', (ev) => {
                                if (ev && ev.detail && ev.detail.localId
                                    && ev.detail.localId !== window.__tsrLocalId) {
                                    return; // not our ticket
                                }
                                this._clearDraft();
                            });
                        },

                        // ─── Draft autosave ───
                        get _draftAvailable() {
                            try {
                                return typeof window !== 'undefined'
                                    && !! window.localStorage;
                            } catch (e) { return false; }
                        },
                        get hasDraft() {
                            if (! this._draftAvailable) return false;
                            try { return !! window.localStorage.getItem(this._draftKey); }
                            catch (e) { return false; }
                        },
                        _scheduleDraftSave() {
                            if (! this._draftAvailable) return;
                            if (this._draftDiscarding) return;
                            // Don't clobber the stored draft with the
                            // initial (empty) form state. The
                            // `livewire:updated` event fires on
                            // mount even though the user hasn't
                            // touched anything, and saving then
                            // would overwrite a draft the user
                            // might be about to recover.
                            if (! this._draftUserTouched) return;
                            if (this._draftSaveTimer) clearTimeout(this._draftSaveTimer);
                            this._draftSaveTimer = setTimeout(() => this._saveDraftNow(), 400);
                        },
                        _saveDraftNow() {
                            this._draftSaveTimer = null;
                            if (! this._draftAvailable) return;
                            if (this._draftDiscarding) return;
                            // Belt-and-braces: if the user hasn't
                            // touched the form yet, do not clobber a
                            // stored draft. (Should already be
                            // prevented by the schedule guard and the
                            // pagehide guard, but we keep this as a
                            // safety net for any future caller.)
                            if (! this._draftUserTouched) return;
                            try {
                                // The form is wrapped in `wire:ignore.self`,
                                // so Livewire's `getData()` does NOT
                                // reflect the user's input — wire:model
                                // is intentionally inert. We read the
                                // values straight out of the DOM
                                // (inputs / textareas / selects) so
                                // the draft mirrors what the user sees.
                                const fields = {};
                                if (this.$el) {
                                    const controls = this.$el.querySelectorAll(
                                        'input[name], textarea[name], select[name], ' +
                                        'input[wire\\:model], textarea[wire\\:model], select[wire\\:model]'
                                    );
                                    controls.forEach((el) => {
                                        const key = el.getAttribute('wire:model')
                                                 || el.name
                                                 || el.id;
                                        if (! key) return;
                                        // Hidden inputs that carry
                                        // signature dataUrls are
                                        // captured separately under
                                        // `signatures` — don't duplicate.
                                        if (el.type === 'hidden' && /SignatureDataUrl$/.test(key)) {
                                            return;
                                        }
                                        let val;
                                        if (el.type === 'checkbox') {
                                            val = !! el.checked;
                                        } else if (el.type === 'radio') {
                                            if (el.checked) val = el.value;
                                            else return;
                                        } else {
                                            val = el.value;
                                        }
                                        if (val === undefined) return;
                                        fields[key] = val;
                                    });
                                }

                                // Signatures are stored in hidden
                                // inputs that the Alpine signature-pad
                                // owns via x-model. Pull them straight
                                // from the DOM (wire:ignore.self means
                                // they don't make it into Livewire
                                // state via wire:model).
                                const readSig = (name) => {
                                    if (! this.$el) return '';
                                    const el = this.$el.querySelector(`input[name="${name}"]`);
                                    return (el && el.value) || '';
                                };
                                const sigs = {
                                    tspSignatureDataUrl:      readSig('tspSignatureDataUrl'),
                                    customerSignatureDataUrl: readSig('customerSignatureDataUrl'),
                                    biomedSignatureDataUrl:   readSig('biomedSignatureDataUrl'),
                                };
                                // Promote the dataUrls into fields too
                                // so a future roundtrip can still
                                // restore the values without needing a
                                // separate hydrate path.
                                if (sigs.tspSignatureDataUrl)      fields.tspSignatureDataUrl      = sigs.tspSignatureDataUrl;
                                if (sigs.customerSignatureDataUrl) fields.customerSignatureDataUrl = sigs.customerSignatureDataUrl;
                                if (sigs.biomedSignatureDataUrl)   fields.biomedSignatureDataUrl   = sigs.biomedSignatureDataUrl;
                                const draft = {
                                    v:           1,
                                    savedAt:     Date.now(),
                                    localId:     window.__tsrLocalId || null,
                                    ticket:      window.__tsrTicketNumber || null,
                                    fields:      fields,
                                };
                                window.localStorage.setItem(this._draftKey, JSON.stringify(draft));
                            } catch (e) {
                                // Quota / serialization errors are
                                // non-fatal — the form still works,
                                // we just can't autosave.
                                console.warn('tsr draft save failed:', e);
                            }
                        },
                        // Manual "Save draft" handler. Wired to the
                        // button in the top header. Always succeeds
                        // even if the user hasn't typed anything yet
                        // (we mark _draftUserTouched = true so the
                        // recovered-draft check on next load still
                        // finds the saved snapshot).
                        saveDraftManual() {
                            // Mark as touched so the stored draft
                            // counts as "the user's" snapshot, not
                            // the empty initial form.
                            this._draftUserTouched = true;
                            this._saveDraftNow();
                            this.draftSavedAt = Date.now();
                            // Clear any previous flash timer.
                            if (this._draftManualTimer) {
                                clearTimeout(this._draftManualTimer);
                            }
                            // The button shows "Saved ✓" for 2s,
                            // then flips back to "Save draft". The
                            // timer is stored on the component so
                            // a fast double-click doesn't pile up.
                            this._draftManualTimer = setTimeout(() => {
                                this.draftSavedAt = null;
                                this._draftManualTimer = null;
                            }, 2000);
                        },
                        _hydrateFromDraft() {
                            if (! this._draftAvailable) return;
                            let raw;
                            try { raw = window.localStorage.getItem(this._draftKey); }
                            catch (e) { return; }
                            if (! raw) return;
                            let draft;
                            try { draft = JSON.parse(raw); } catch (e) { return; }
                            if (! draft || ! draft.fields) return;

                            // Sanity-check: draft must match the
                            // ticket we're on. (We do NOT compare
                            // localId here — it's a random uuid that
                            // the server regenerates on every
                            // request, so a draft from a previous
                            // request would never match. The ticket
                            // number is the only stable identifier.)
                            if (draft.ticket && window.__tsrTicketNumber
                                && String(draft.ticket) !== String(window.__tsrTicketNumber)) {
                                return;
                            }

                            const root = this.$root.closest('[wire\\:id]');
                            if (! root) return;
                            const id = root.getAttribute('wire:id');
                            const comp = (window.Livewire && id) ? window.Livewire.find(id) : null;

                            // Push text/numeric fields back to the DOM
                            // first (the form is wrapped in
                            // `wire:ignore.self`, so wire:model is
                            // intentionally inert and Livewire.set()
                            // alone would not change what the user
                            // sees). We then also try comp.set() so
                            // the server-side state matches.
                            const skip = new Set([
                                'tspSignatureDataUrl',
                                'customerSignatureDataUrl',
                                'biomedSignatureDataUrl',
                            ]);
                            const findControl = (key) => {
                                if (! this.$el) return null;
                                return this.$el.querySelector(
                                    `[wire\\:model="${key}"], [name="${key}"]`
                                );
                            };
                            this._hydrating = true;
                            try {
                            for (const [k, v] of Object.entries(draft.fields)) {
                                if (skip.has(k)) continue;
                                if (v === undefined || v === null) continue;
                                const el = findControl(k);
                                if (el) {
                                    try {
                                        if (el.type === 'checkbox') {
                                            el.checked = !! v;
                                        } else if (el.type === 'radio') {
                                            el.checked = (el.value === String(v));
                                        } else {
                                            el.value = v;
                                        }
                                        // Defer event dispatch to the
                                        // next tick so listeners that
                                        // would clobber the value (e.g.
                                        // a Livewire/Alpine sync) get a
                                        // chance to run AFTER we've set
                                        // it. The `input` event is what
                                        // marks the form as
                                        // user-touched in our own
                                        // listener, so the draft
                                        // autosave doesn't trigger a
                                        // roundtrip immediately.
                                        setTimeout(() => {
                                            el.dispatchEvent(new Event('input',  { bubbles: true }));
                                            el.dispatchEvent(new Event('change', { bubbles: true }));
                                        }, 0);
                                    } catch (e) { /* ignore */ }
                                }
                                if (comp && typeof comp.set === 'function') {
                                    try { comp.set(k, v); } catch (e) { /* ignore */ }
                                }
                            }
                            } finally {
                                this._hydrating = false;
                            }

                            // Restore signatures onto the canvases.
                            this.$nextTick(() => {
                                if (draft.signatures) {
                                    if (draft.signatures.tspSignatureDataUrl) {
                                        this._restoreSignature('tspSignatureDataUrl',      draft.signatures.tspSignatureDataUrl);
                                    }
                                    if (draft.signatures.customerSignatureDataUrl) {
                                        this._restoreSignature('customerSignatureDataUrl', draft.signatures.customerSignatureDataUrl);
                                    }
                                    if (draft.signatures.biomedSignatureDataUrl) {
                                        this._restoreSignature('biomedSignatureDataUrl',   draft.signatures.biomedSignatureDataUrl);
                                    }
                                }
                            });

                            this._draftRestored = true;
                            window.dispatchEvent(new CustomEvent('tsr.draft-restored', { detail: { localId: window.__tsrLocalId } }));
                        },
                        _restoreSignature(name, dataUrl) {
                            try {
                                // Find the canvas that belongs to the
                                // hidden input with this name. The
                                // signature-pad component puts them
                                // inside the same .signature-pad root.
                                const root = this.$root.closest('[wire\:id]');
                                if (! root) return;
                                const inputs = root.querySelectorAll('input[type="hidden"]');
                                for (const inp of inputs) {
                                    if (inp.getAttribute('name') !== name) continue;
                                    const padRoot = inp.closest('.signature-pad');
                                    if (! padRoot) break;
                                    // Alpine owns the canvas; we read
                                    // it back from the data attribute
                                    // it stamped in init() (we add it
                                    // in the patch below). Fall back to
                                    // walking the DOM.
                                    const canvas = padRoot.querySelector('canvas');
                                    if (! canvas) break;
                                    const ctx = canvas.getContext('2d');
                                    const img = new Image();
                                    img.onload = () => {
                                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                                        // Tell the Alpine pad instance
                                        // that the canvas now has ink so
                                        // its `hasInk` flag is honest.
                                        // The pad listens for this
                                        // custom event.
                                        window.dispatchEvent(new CustomEvent('tsr.signature-hydrated', {
                                            detail: { name: name, dataUrl: dataUrl }
                                        }));
                                    };
                                    img.src = dataUrl;
                                    break;
                                }
                            } catch (e) { /* ignore */ }
                        },
                        discardDraft() {
                            this._draftDiscarding = true;
                            this._clearDraft();
                            // Reload to start from a clean state.
                            window.location.reload();
                        },
                        _clearDraft() {
                            if (! this._draftAvailable) return;
                            try { window.localStorage.removeItem(this._draftKey); }
                            catch (e) { /* ignore */ }
                        },

                        // ─── Sync-state computed pill ───
                        get syncPillClass() {
                            // Body uses DaisyUI badge classes via
                            // :class binding on the header span.
                            return '';
                        },
                        get syncPillIcon() {
                            if (this.error   > 0)            return '⚠';
                            if (this.syncing > 0)            return '↻';
                            if (this.pending > 0)            return '◌';
                            if (this.synced  > 0)            return '✓';
                            return '·';
                        },
                        get syncPillLabel() {
                            if (this.error   > 0)            return this.error   + ' sync failed';
                            if (this.syncing > 0)            return 'Syncing to Monday…';
                            if (this.pending > 0)            return this.pending + ' queued';
                            if (this.synced  > 0)            return 'Synced to Monday';
                            return 'No TSR yet';
                        },
                        get syncPillTitle() {
                            const parts = [
                                this.pending > 0 ? (this.pending + ' pending') : null,
                                this.syncing > 0 ? (this.syncing + ' syncing') : null,
                                this.synced  > 0 ? (this.synced  + ' synced')  : null,
                                this.error   > 0 ? (this.error   + ' error')   : null,
                            ].filter(Boolean);
                            if (parts.length === 0) return 'No TSRs for this ticket yet';
                            if (this.lastError) {
                                return parts.join(' · ') + '\\nLast error: ' + this.lastError;
                            }
                            return parts.join(' · ');
                        },
                        get manualSyncTitle() {
                            if (this.syncInFlight) return 'Sync in progress…';
                            if (this.error > 0)     return 'Retry sending the failed TSRs to Monday.com';
                            if (this.pending > 0)   return 'Send the queued TSR to Monday.com now';
                            if (! this.online)      return 'You appear to be offline — connect first';
                            return 'Force a re-sync of all TSRs for this ticket';
                        },
                        get lastSyncedHuman() {
                            if (! this.lastSyncedAt) return '';
                            try {
                                const d = new Date(this.lastSyncedAt);
                                return d.toLocaleString();
                            } catch (e) { return this.lastSyncedAt; }
                        },

                        // ─── Connection actions ───
                        forceOffline() {
                            this.forcedOffline = ! this.forcedOffline;
                            this.online = ! this.forcedOffline;
                            if (this.online) this.queueDrain();
                        },

                        queueDrain() {
                            // Fire-and-forget: ask the server to drain any
                            // pending TSRs for this ticket. We don't need
                            // to wait for the response — fetchStatus() will
                            // pick up the new state on its next tick.
                            this.manualSync(true /* silent */);
                        },

                        async manualSync(silent = false) {
                            if (this.syncInFlight) return;
                            if (! this.online) {
                                if (! silent) {
                                    window.dispatchEvent(new CustomEvent('tsr.sync_blocked_offline'));
                                }
                                return;
                            }
                            this.syncInFlight = true;
                            try {
                                const r = await fetch(
                                    window.__tsrSyncUrl || '/tsp/tickets/' + (window.__tsrTicketNumber || '') + '/tsr/sync',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest',
                                            'Accept': 'application/json',
                                        },
                                        credentials: 'same-origin',
                                    }
                                );
                                if (r.ok) {
                                    window.dispatchEvent(new CustomEvent('tsr.synced', {
                                        detail: await r.json().catch(() => ({})),
                                    }));
                                } else {
                                    this.lastError = 'Server returned ' + r.status;
                                }
                            } catch (e) {
                                this.lastError = e.message || 'Network error';
                            } finally {
                                this.syncInFlight = false;
                                // Refresh the pill right after a manual sync
                                // so the user sees the new state immediately.
                                this.fetchStatus();
                            }
                        },

                        async fetchStatus() {
                            const ticket = window.__tsrTicketNumber;
                            if (! ticket) return;
                            try {
                                const r = await fetch(
                                    (window.__tsrStatusUrl || '/tsp/tickets/' + ticket + '/tsr/status'),
                                    {
                                        headers: { 'Accept': 'application/json' },
                                        credentials: 'same-origin',
                                    }
                                );
                                if (! r.ok) return;
                                const data = await r.json();
                                if (! data || data.ok === false) return;
                                this.pending       = data.pending   || 0;
                                this.syncing       = data.syncing   || 0;
                                this.synced        = data.synced    || 0;
                                this.error         = data.error     || 0;
                                this.lastError     = data.last_error || null;
                                this.lastSyncedAt  = data.last_synced_at || null;
                                // Update data-* attrs (useful for e2e tests).
                                const el = this.$refs.syncPill;
                                if (el) {
                                    el.setAttribute('data-pending', this.pending);
                                    el.setAttribute('data-syncing', this.syncing);
                                    el.setAttribute('data-synced',  this.synced);
                                    el.setAttribute('data-error',   this.error);
                                }
                            } catch (e) { /* silent — next tick will retry */ }
                        },
                    };
                };

                // ----------------------------------------------------------------
                //  tsrTimeAutofill — tiny Alpine component for the
                //  "auto-fill blank timestamps" tip. It runs once
                //  on mount and patches any of the four timestamp
                //  fields (serviceStartDateTime, logInDate,
                //  serviceEndDateTime, logOutDate) that the TSP
                //  left blank with the current device time.
                //  Lives as its own component so the markup
                //  doesn't have to ship a multi-line JS expression
                //  through an x-init attribute (which Alpine can't
                //  reliably parse when it contains // comments).
                // ----------------------------------------------------------------
                window.tsrTimeAutofill = function () {
                    return {
                        init() {
                            this.$nextTick(() => {
                                try {
                                    const pad = (n) => String(n).padStart(2, '0');
                                    const nowIso = () => {
                                        const d = new Date();
                                        d.setSeconds(0, 0);
                                        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                                            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                                    };
                                    if (! this.$wire.get('serviceStartDateTime')) this.$wire.set('serviceStartDateTime', nowIso());
                                    if (! this.$wire.get('logInDate'))           this.$wire.set('logInDate',           nowIso());
                                    if (! this.$wire.get('serviceEndDateTime'))  this.$wire.set('serviceEndDateTime',  nowIso());
                                    if (! this.$wire.get('logOutDate'))          this.$wire.set('logOutDate',          nowIso());
                                } catch (e) { /* silent — don't break the page over a tip */ }
                            });
                        },
                    };
                };
            </script>
        @endpush
    @endonce
</div>
