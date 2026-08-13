<?php

use App\Models\User;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $account_name = '';
    public string $branch = '';

    /**
     * Sentinel value used in the branch <select> when the user's
     * existing branch isn't in the canonical list (e.g. an old value
     * like "St. Luke's BGC" or a brand-new site). Setting this reveals
     * a freeform text input below the dropdown so the user can still
     * correct it.
     */
    public string $branchCustom = '';

    /**
     * Convenience flag derived from $branch — true when the
     * branch value isn't in the dropdown list (so the user is on the
     * "Other" escape hatch and the freeform input should be visible).
     */
    public bool $branchIsCustom = false;

    // Equipment management
    public bool $showMachineForm = false;
    public ?int $editingMachineId = null;
    public string $machine_brand = '';
    public string $machine_model = '';
    public string $machine_serial_number = '';
    public string $machine_nickname = '';
    public bool $machine_is_primary = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->account_name = $user->account_name ?? '';
        $this->branch = $user->branch ?? '';
    }

    /**
     * Get the user's machines.
     */
    public function getMachinesProperty()
    {
        return Machine::where('user_id', Auth::id())
            ->orderBy('is_primary', 'desc')
            ->orderBy('brand')
            ->get();
    }

    /**
     * Canonical list of branch offices to show in the branch <select>.
     * We use a fixed four-region taxonomy (NCR / North Luzon / Visayas
     * / Mindanao) so the dropdown stays stable regardless of how many
     * distinct junk strings have been written into users.branch over
     * time. The user's own current branch (if any) is forced in so
     * the current value is always selectable until they save a
     * corrected region.
     *
     * @return array<int, string>
     */
    public function getBranchesOptionListProperty(): array
    {
        $canonical = [
            'National Capital Region',
            'North Luzon',
            'Visayas',
            'Mindanao',
        ];

        $user = Auth::user();
        $current = trim((string) ($user->branch ?? ''));

        if ($current !== '' && ! in_array($current, $canonical, true)) {
            // Keep the user's legacy value selectable until they pick
            // a canonical region on next save. Sort alphabetically.
            $canonical[] = $current;
            usort($canonical, fn ($a, $b) => strcasecmp($a, $b));
        }

        return $canonical;
    }

    /**
     * True when the user's current branch already in the dropdown
     * list (so we don't need to show the freeform "Other" input).
     */
    public function getBranchIsInListProperty(): bool
    {
        $current = trim($this->branch);
        if ($current === '') {
            return true;
        }

        return in_array($current, $this->branchesOptionList, true);
    }

    /**
     * Hook into the live update so the page can react when the user
     * picks "Other" from the dropdown. We keep the underlying
     * $branch field authoritative and only set the flag for the
     * template to read.
     */
    public function updatedBranch(): void
    {
        $this->branchIsCustom = $this->branch === '__custom__';
    }

    /**
     * Called right before validation: normalize the branch value.
     * If the user picked "Other" and typed in the freeform input,
     * lift that text into $branch (trimmed). Otherwise leave $branch
     * as-is.
     */
    public function updatedBranchCustom(): void
    {
        $this->branchCustom = trim($this->branchCustom);
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        // If the user picked "Other" from the dropdown, capture the
        // freeform text into the real $branch field before validating.
        $this->branch = trim($this->branch);
        if ($this->branch === '__custom__') {
            $this->branch = trim($this->branchCustom);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'account_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // Reset the freeform fields so a stale "Other" text doesn't
        // hang around after a successful save.
        $this->branchCustom = '';
        $this->branchIsCustom = false;

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Open the machine form for creating a new machine.
     */
    public function createMachine(): void
    {
        $this->editingMachineId = null;
        $this->machine_brand = '';
        $this->machine_model = '';
        $this->machine_serial_number = '';
        $this->machine_nickname = '';
        $this->machine_is_primary = $this->machines->isEmpty();
        $this->showMachineForm = true;
    }

    /**
     * Open the machine form for editing an existing machine.
     */
    public function editMachine(int $machineId): void
    {
        $machine = Machine::where('user_id', Auth::id())->findOrFail($machineId);

        $this->editingMachineId = $machine->id;
        $this->machine_brand = $machine->brand ?? '';
        $this->machine_model = $machine->model ?? '';
        $this->machine_serial_number = $machine->serial_number ?? '';
        $this->machine_nickname = $machine->nickname ?? '';
        $this->machine_is_primary = $machine->is_primary;
        $this->showMachineForm = true;
    }

    /**
     * Save the machine (create or update).
     */
    public function saveMachine(): void
    {
        $validated = $this->validate([
            'machine_brand' => ['required', 'string', 'max:120'],
            'machine_model' => ['required', 'string', 'max:120'],
            'machine_serial_number' => ['nullable', 'string', 'max:120'],
            'machine_nickname' => ['nullable', 'string', 'max:120'],
            'machine_is_primary' => ['boolean'],
        ]);

        $userId = Auth::id();

        // If marking as primary, unset primary on all other machines
        if ($validated['machine_is_primary']) {
            Machine::where('user_id', $userId)
                ->when($this->editingMachineId, fn($q) => $q->where('id', '!=', $this->editingMachineId))
                ->update(['is_primary' => false]);
        }

        if ($this->editingMachineId) {
            // Update existing machine
            $machine = Machine::where('user_id', $userId)->findOrFail($this->editingMachineId);
            $machine->update([
                'brand' => $validated['machine_brand'],
                'model' => $validated['machine_model'],
                'serial_number' => $validated['machine_serial_number'],
                'nickname' => $validated['machine_nickname'],
                'is_primary' => $validated['machine_is_primary'],
            ]);
        } else {
            // Create new machine
            Machine::create([
                'user_id' => $userId,
                'brand' => $validated['machine_brand'],
                'model' => $validated['machine_model'],
                'serial_number' => $validated['machine_serial_number'],
                'nickname' => $validated['machine_nickname'],
                'is_primary' => $validated['machine_is_primary'],
            ]);
        }

        $this->showMachineForm = false;
        $this->editingMachineId = null;
        $this->dispatch('machine-saved');
    }

    /**
     * Delete a machine.
     */
    public function deleteMachine(int $machineId): void
    {
        $machine = Machine::where('user_id', Auth::id())->findOrFail($machineId);
        $machine->delete();
        $this->dispatch('machine-deleted');
    }

    /**
     * Cancel the machine form.
     */
    public function cancelMachineForm(): void
    {
        $this->showMachineForm = false;
        $this->editingMachineId = null;
    }
}; ?>

<section class="space-y-6">
    <x-ui.card
        title="Profile information"
        subtitle="Update your name, email, hospital, and branch. New email addresses will need to be re-verified."
        padding="p-6"
    >
        <x-slot:icon>
            <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
        </x-slot:icon>

        <form wire:submit="updateProfileInformation" class="space-y-5">
            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input wire:model="name" id="name" name="name" type="text" class="mt-1 block w-full" required autofocus autocomplete="name" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="email" id="email" name="email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                    <div>
                        <p class="text-sm mt-2 text-base-content/80">
                            {{ __('Your email address is unverified.') }}

                            <button wire:click.prevent="sendVerification" class="underline text-sm text-base-content/60 hover:text-base-content rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 font-medium text-sm text-success">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Hospital / account name is a customer-only field.
                 TSP / manager / admin accounts don't need (and can't
                 meaningfully edit) the hospital they were provisioned
                 against — it's the customer's identity, not the TSP's.
                 Branch / department is still surfaced for everyone:
                 TSPs occasionally land in the wrong branch when seeded
                 from monday.com and need to fix it themselves. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if (auth()->user()?->isCustomer())
                <div>
                    <x-input-label for="account_name" :value="__('Hospital / account name')" />
                    <x-text-input wire:model="account_name" id="account_name" name="account_name" type="text" class="mt-1 block w-full" autocomplete="organization" />
                    <x-input-error class="mt-2" :messages="$errors->get('account_name')" />
                </div>
                @endif
                <div>
                    <x-input-label for="branch" :value="__('Branch')" />
                    <select
                        wire:model.live="branch"
                        id="branch"
                        name="branch"
                        class="mt-1 block w-full rounded-lg border-brand-mist bg-white text-brand-navy shadow-sm transition focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/30 focus:ring-offset-0"
                        autocomplete="address-level2"
                    >
                        <option value="">{{ __('— Select branch —') }}</option>
                        @foreach ($this->branchesOptionList as $option)
                            <option value="{{ $option }}" @selected($this->branch === $option)>{{ $option }}</option>
                        @endforeach
                        <option value="__custom__" @selected($this->branchIsCustom)>{{ __('Other (please specify)') }}</option>
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('branch')" />

                    @if ($this->branchIsCustom)
                        <div class="mt-2">
                            <x-input-label for="branchCustom" :value="__('Custom branch / department')" class="sr-only" />
                            <x-text-input
                                wire:model="branchCustom"
                                id="branchCustom"
                                name="branchCustom"
                                type="text"
                                class="mt-1 block w-full"
                                :placeholder="__('Type the branch or department name')"
                                autocomplete="address-level2"
                            />
                            <x-input-error class="mt-2" :messages="$errors->get('branchCustom')" />
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-4">
                <x-primary-button>{{ __('Save') }}</x-primary-button>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-ui.card>
</section>
