<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                    New service request
                </p>
                <h2 class="font-bold text-3xl text-base-content leading-tight mt-1">
                    Tell us what's happening
                </h2>
                <p class="text-sm text-base-content/60 mt-1.5">
                    Our team will take it from there. We usually reply within a few hours during business days.
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-sm gap-1.5 shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to dashboard
            </a>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">

            {{-- ───── Existing-ticket warning ───── --}}
            @if (session('duplicate_tickets'))
                @php $dupes = session('duplicate_tickets'); @endphp
                <x-ui.card tone="warning" padding="p-5">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-warning/15 text-warning flex items-center justify-center text-xl flex-shrink-0" aria-hidden="true">
                            !
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-base-content">
                                You already have {{ count($dupes) === 1 ? 'an open ticket' : count($dupes) . ' open tickets' }}
                                with the same subject.
                            </h3>
                            <p class="mt-1 text-sm text-base-content/70">
                                Please review the existing request{{ count($dupes) === 1 ? '' : 's' }} — our team may
                                already be working on it.
                            </p>
                            <ul class="mt-3 space-y-2">
                                @foreach ($dupes as $d)
                                    <li class="flex items-start gap-2 bg-base-100 border border-warning/30 rounded-lg px-3 py-2">
                                        <span class="mt-0.5">🎫</span>
                                        <div class="flex-1 min-w-0">
                                            <a href="{{ route('tickets.show', ['id' => $d['id']]) }}"
                                               class="text-primary hover:text-primary/80 font-medium break-words">
                                                #{{ $d['id'] }} — {{ $d['name'] }}
                                            </a>
                                            <div class="text-xs text-base-content/60 mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5">
                                                @if (! empty($d['status_text']))       <span>Status: <span class="font-medium">{{ $d['status_text'] }}</span></span> @endif
                                                @if (! empty($d['request_type_text'])) <span>Type: <span class="font-medium">{{ $d['request_type_text'] }}</span></span> @endif
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            @if (count($dupes) >= 2)
                                <p class="mt-3 text-xs text-base-content/60 italic">
                                    You already have {{ count($dupes) }} open tickets with this subject.
                                    Please contact our support team to consolidate them before submitting a new one.
                                </p>
                            @else
                                <form method="POST"
                                      action="{{ route('tickets.store', ['force' => 1]) }}"
                                      class="mt-3 flex items-center justify-end gap-2">
                                    @csrf
                                    <input type="hidden" name="subject"      value="{{ old('subject') }}">
                                    <input type="hidden" name="description"  value="{{ old('description') }}">
                                    <input type="hidden" name="request_type" value="{{ old('request_type') }}">
                                    <input type="hidden" name="machine_id"   value="{{ old('machine_id') }}">
                                    <input type="hidden" name="brand"        value="{{ old('brand') }}">
                                    <input type="hidden" name="model"        value="{{ old('model') }}">
                                    <input type="hidden" name="serial"       value="{{ old('serial') }}">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        Submit anyway
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @endif

            @if ($errors->any())
                <x-ui.toast type="error" title="Please fix the following">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.toast>
            @endif

            <form method="POST" action="{{ route('tickets.store') }}" class="space-y-5">
                @csrf

                {{-- ───── Account info card ───── --}}
                <x-ui.card padding="p-5">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-primary-content flex items-center justify-center text-lg font-semibold">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-base-content">{{ $user->name }}</div>
                            <div class="text-xs text-base-content/60 flex flex-wrap items-center gap-x-2 mt-0.5">
                                <span>{{ $user->account_name ?: 'Account not set' }}</span>
                                @if($user->branch)
                                    <span class="text-base-content/30">·</span>
                                    <span>{{ $user->branch }}</span>
                                @endif
                                <span class="text-base-content/30">·</span>
                                <span>{{ $user->email }}</span>
                            </div>
                        </div>
                    </div>
                </x-ui.card>

                {{-- ───── Machine selector ─────
                     If the customer has registered machines, show a
                     dropdown of their machines. Otherwise fall back
                     to the free-text brand/model picker. --}}
                <x-ui.card padding="p-0"
                    title="Affected equipment"
                    subtitle="Select the machine that needs service, or describe it manually."
                >
                    <x-slot:icon>
                        <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-info/10 text-info flex items-center justify-center shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        </span>
                    </x-slot:icon>
                    <div class="px-5 py-4">
                        @if ($machines->isNotEmpty())
                            {{-- Customer has registered machines — show dropdown --}}
                            <div x-data="{ machineId: '{{ old('machine_id', '') }}', showManual: {{ old('machine_id') ? 'false' : 'true' }} }">
                                <label class="block text-sm font-medium text-base-content mb-2">Your registered equipment</label>
                                <div class="space-y-2">
                                    @foreach ($machines as $machine)
                                        <label class="flex items-center gap-3 px-4 py-3 border border-base-300 rounded-xl cursor-pointer hover:bg-base-200/60 transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                            <input type="radio"
                                                   name="machine_id"
                                                   value="{{ $machine->id }}"
                                                   x-model="machineId"
                                                   @change="showManual = false"
                                                   class="radio radio-primary radio-sm"
                                                   @checked(old('machine_id') == $machine->id)>
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium text-base-content">
                                                    {{ $machine->brand }} {{ $machine->model }}
                                                    @if($machine->is_primary)
                                                        <span class="badge badge-primary badge-sm ml-1">Primary</span>
                                                    @endif
                                                </div>
                                                @if($machine->serial_number)
                                                    <div class="text-xs text-base-content/60">S/N: {{ $machine->serial_number }}</div>
                                                @endif
                                                @if($machine->nickname)
                                                    <div class="text-xs text-base-content/40">{{ $machine->nickname }}</div>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach

                                    {{-- Manual entry option --}}
                                    <label class="flex items-center gap-3 px-4 py-3 border border-dashed border-base-300 rounded-xl cursor-pointer hover:bg-base-200/60 transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                        <input type="radio"
                                               name="machine_id"
                                               value=""
                                               x-model="machineId"
                                               @change="showManual = true"
                                               class="radio radio-primary radio-sm"
                                               @checked(!old('machine_id') && $machines->isEmpty())>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-base-content/80">Different machine (enter manually)</div>
                                        </div>
                                    </label>
                                </div>

                                {{-- Manual brand/model inputs --}}
                                <div x-show="showManual" x-cloak class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label for="brand" class="block text-[13px] font-semibold text-base-content mb-1">Brand</label>
                                        <input type="text" id="brand" name="brand" maxlength="120"
                                               value="{{ old('brand') }}"
                                               placeholder="e.g. Mindray"
                                               class="input input-bordered input-sm h-9 text-[13px] bg-base-200/50 w-full">
                                    </div>
                                    <div>
                                        <label for="model" class="block text-[13px] font-semibold text-base-content mb-1">Model</label>
                                        <input type="text" id="model" name="model" maxlength="120"
                                               value="{{ old('model') }}"
                                               placeholder="e.g. BC-6800"
                                               class="input input-bordered input-sm h-9 text-[13px] bg-base-200/50 w-full">
                                    </div>
                                    <div>
                                        <label for="serial" class="block text-[13px] font-semibold text-base-content mb-1">Serial #</label>
                                        <input type="text" id="serial" name="serial" maxlength="120"
                                               value="{{ old('serial') }}"
                                               placeholder="Optional"
                                               class="input input-bordered input-sm h-9 text-[13px] bg-base-200/50 w-full">
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- No registered machines — show free-text inputs --}}
                            <div class="alert bg-base-200 border border-base-300 text-base-content/80 rounded-xl mb-4">
                                <span aria-hidden="true" class="text-xl">💡</span>
                                <div class="text-sm">
                                    You haven't registered any equipment yet. Please describe the machine that needs service.
                                    <a href="{{ route('profile') }}" class="link link-primary">Register equipment in your profile</a>
                                    for faster ticket submission next time.
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label for="brand" class="block text-[13px] font-semibold text-base-content mb-1">Brand</label>
                                    <input type="text" id="brand" name="brand" maxlength="120"
                                           value="{{ old('brand', $user->brand) }}"
                                           placeholder="e.g. Mindray"
                                           class="input input-bordered h-9 text-[13px] bg-base-200/50 w-full">
                                </div>
                                <div>
                                    <label for="model" class="block text-[13px] font-semibold text-base-content mb-1">Model</label>
                                    <input type="text" id="model" name="model" maxlength="120"
                                           value="{{ old('model', $user->model) }}"
                                           placeholder="e.g. BC-6800"
                                           class="input input-bordered h-9 text-[13px] bg-base-200/50 w-full">
                                </div>
                                <div>
                                    <label for="serial" class="block text-[13px] font-semibold text-base-content mb-1">Serial #</label>
                                    <input type="text" id="serial" name="serial" maxlength="120"
                                           value="{{ old('serial', $user->serial_number) }}"
                                           placeholder="Optional"
                                           class="input input-bordered h-9 text-[13px] bg-base-200/50 w-full">
                                </div>
                            </div>
                        @endif
                    </div>
                </x-ui.card>

                {{-- ───── Technician assignment info ─────
                     Customers no longer pick technicians. The ticket
                     goes into the regional pool and available TSPs
                     claim it from their dashboard. --}}
                <x-ui.card padding="p-5">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-secondary/10 text-secondary flex items-center justify-center text-xl flex-shrink-0" aria-hidden="true">
                            👷
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-base-content">Technician assignment</h3>
                            <p class="mt-1 text-sm text-base-content/60">
                                You don't need to pick a technician. Your request will be routed to the next available field engineer in your area.
                            </p>
                        </div>
                    </div>
                </x-ui.card>

                {{-- ───── Request details ───── --}}
                <x-ui.card padding="p-0"
                    title="Request details"
                >
                    <x-slot:icon>
                        <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        </span>
                    </x-slot:icon>
                    <div class="px-5 py-4 space-y-4">
                        <div>
                            <label for="subject" class="block text-[13px] font-semibold text-base-content mb-1">
                                Subject <span class="text-error">*</span>
                            </label>
                            <input type="text" name="subject" id="subject" required maxlength="255"
                                   value="{{ old('subject') }}"
                                   placeholder="e.g. BC-6800 returns error code E-204 on startup"
                                   class="input input-bordered h-9 text-[13px] bg-base-200/50 w-full @error('subject') input-error @enderror">
                            @error('subject')
                                <p class="mt-1 text-xs text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="description" class="block text-[13px] font-semibold text-base-content mb-1">
                                Description <span class="text-error">*</span>
                            </label>
                            <textarea name="description" id="description" rows="5" required maxlength="5000"
                                      placeholder="What happened? When did it start? Any error messages, unusual sounds, or smells?"
                                      class="textarea textarea-bordered text-sm bg-base-200/50 w-full @error('description') textarea-error @enderror">{{ old('description') }}</textarea>
                            @error('description')
                                <p class="mt-1 text-xs text-error">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-base-content/50">Be as specific as you can — our technicians will read this first.</p>
                        </div>

                        <div>
                            <label for="request_type" class="block text-[13px] font-semibold text-base-content mb-1">
                                Request type <span class="text-error">*</span>
                            </label>
                            <select name="request_type" id="request_type" required
                                    class="select select-bordered h-9 text-[13px] bg-base-200/50 w-full @error('request_type') select-error @enderror">
                                <option value="">Select type…</option>
                                @foreach($requestTypes as $rt)
                                    <option value="{{ $rt }}" @selected(old('request_type') === $rt)>{{ $rt }}</option>
                                @endforeach
                            </select>
                            @error('request_type')
                                <p class="mt-1 text-xs text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </x-ui.card>

                {{-- ───── Submit ───── --}}
                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost h-11 px-5">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary gap-2 h-11 px-5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Submit request
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
