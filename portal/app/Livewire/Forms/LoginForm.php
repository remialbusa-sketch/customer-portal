<?php

namespace App\Livewire\Forms;

use App\Models\User;
use App\Services\MondayCustomerDirectory;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // First login of a new customer: the account doesn't exist locally
        // yet but the customer is on the monday.com Customer Details board.
        // Provision the account on the fly with the default password, then
        // force a password change before they can use the portal.
        if (! User::where('email', $this->email)->exists()
            && $this->password === User::DEFAULT_PASSWORD) {
            $this->provisionFromMondayBoard();
        }

        if (! Auth::attempt($this->only(['email', 'password']), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        // Block suspended / pending accounts from logging in.
        $user = Auth::user();
        if ($user && ! $user->isActive()) {
            Auth::logout();
            $user->delete();

            throw ValidationException::withMessages([
                'form.email' => "Your account is {$user->status}. Contact your administrator.",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Create the local customer account from the monday.com Customer
     * Details board. Uses the same smart cache-retry as the admin
     * invite flow: check the cached snapshot, and if the customer is
     * missing (likely added seconds ago), flush the 5-minute cache and
     * re-check once.
     */
    protected function provisionFromMondayBoard(): void
    {
        $directory = app(MondayCustomerDirectory::class);

        $customer = $directory->findByEmail($this->email);
        if (! $customer) {
            $directory->flushCache();
            $customer = $directory->findByEmail($this->email);
        }

        if (! $customer) {
            return;
        }

        User::create([
            'name'                 => $customer['name'] ?? Str::ucfirst(strtok($this->email, '@')),
            'email'                => Str::lower(trim($this->email)),
            'password'             => Hash::make(User::DEFAULT_PASSWORD),
            'role'                 => 'customer',
            'status'               => 'active',
            'must_change_password' => true,
            'monday_id'            => $customer['id'] ?? null,
            'account_name'         => $customer['account_name'] ?? null,
            'branch'               => $customer['branch'] ?? null,
            'region'               => $customer['region'] ?? null,
            'address'              => $customer['address'] ?? null,
        ]);

        Log::info('Customer account auto-provisioned on first login.', [
            'email' => $this->email,
            'monday_id' => $customer['id'] ?? null,
        ]);
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
