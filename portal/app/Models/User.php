<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

#[Fillable([
    'name', 'email', 'password',
    'role', 'status', 'must_change_password', 'monday_id',
    'team', 'region', 'skills',
    'branch', 'address', 'account_name', 'brand', 'model',
    'serial_number', 'installation_date',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Default password for accounts auto-provisioned from the
     * monday.com Customer Details board (or the TSP roster).
     * Anyone still using it sees the "temporary password" notice
     * until they set a password of their own.
     */
    public const DEFAULT_PASSWORD = 'Password!123';

    /**
     * Memoized result of {@see usingDefaultPassword()}: one bcrypt
     * check per request at most.
     */
    private ?bool $usesDefaultPassword = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'skills'            => 'array',
            'installation_date' => 'date',
            'must_change_password' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------
    // Role helpers
    // -----------------------------------------------------------------

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isTsp(): bool
    {
        return in_array($this->role, ['fse', 'its'], true);
    }

    public function isFse(): bool
    {
        return $this->role === 'fse';
    }

    public function isIts(): bool
    {
        return $this->role === 'its';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * True when the account was auto-provisioned with the default
     * password and still hasn't been changed.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * True when the account's password is STILL the default
     * (Password!123) — whether it was auto-provisioned from the
     * monday.com board or seeded by an admin. Drives the
     * "temporary password" notice in the navigation, which shows
     * for every user who hasn't set a password of their own.
     *
     * Memoized per model instance so the bcrypt check runs at most
     * once per request.
     */
    public function usingDefaultPassword(): bool
    {
        return $this->usesDefaultPassword ??= Hash::check(
            self::DEFAULT_PASSWORD,
            (string) $this->getAuthPassword()
        );
    }

    /**
     * The route name we should send this user to after login.
     */
    public function homeRoute(): string
    {
        return match ($this->role) {
            'superadmin'                         => 'admin.kpi',
            'admin'                              => 'admin.kpi',
            'fse', 'its', 'manager'              => 'tsp.dashboard',
            default                              => 'dashboard',
        };
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * All service reports this user has authored (as a TSP). Used by
     * the per-TSP performance widget on the executive KPI dashboard.
     */
    public function serviceReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceReport::class);
    }

    /**
     * All machines registered to this user (as a customer). The
     * customer profile's "My machines" form and the new-ticket
     * equipment picker both read from this relation. Ordered
     * primary-first, then most-recently-updated.
     */
    public function machines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Machine::class)
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at');
    }
}
