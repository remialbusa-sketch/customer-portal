<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerInviteMail;
use App\Models\CustomerInvite;
use App\Models\User;
use App\Services\MondayCustomerDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Admin UI for issuing customer registration invites.
 *
 * Wraps the same logic as `php artisan portal:invite-customer` so
 * the superadmin can hand out links from a web page instead of the
 * command line. The form is intentionally simple — one email,
 * optional TTL, and an "invalidate existing" checkbox.
 */
class InviteController extends Controller
{
    public function index(): View
    {
        $recent = CustomerInvite::query()
            ->with('usedBy')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('admin.invites', [
            'user'    => auth()->user(),
            'recent'  => $recent,
            'defaultTtl' => CustomerInvite::DEFAULT_TTL_DAYS,
        ]);
    }

    public function store(
        Request $request,
        MondayCustomerDirectory $directory,
    ): RedirectResponse {
        $data = $request->validate([
            'email'               => ['required', 'email:rfc', 'max:191'],
            'ttl'                 => ['nullable', 'integer', 'min:1', 'max:365'],
            'invalidate_existing' => ['nullable', 'boolean'],
            'send_email'          => ['nullable', 'boolean'],
        ]);

        $email = strtolower(trim($data['email']));
        $ttl   = (int) ($data['ttl'] ?? CustomerInvite::DEFAULT_TTL_DAYS);

        // Look the customer up on monday.com. We don't pass --no-monday
        // through the UI — that flag is dev-only. If the board API is
        // unreachable or the email isn't on the board, the form surfaces
        // a clear error and no invite row is created.
        try {
            $customer = $directory->findByEmail($email);
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'monday.com lookup failed: ' . $e->getMessage()]);
        }

        if (! $customer) {
            return back()
                ->withInput()
                ->withErrors(['email' => "No customer found on the monday.com Customer Details board for {$email}. Add them to the board first."]);
        }

        if ($request->boolean('invalidate_existing')) {
            $n = CustomerInvite::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        $invite = CustomerInvite::create([
            'token'              => CustomerInvite::generateToken(),
            'email'              => $email,
            'account_name'       => $customer['account_name'] ?? '',
            'branch'             => $customer['branch']       ?? '',
            'region'             => $customer['region']       ?? null,
            'address'            => $customer['address']      ?? null,
            'monday_customer_id' => $customer['id']            ?? null,
            'expires_at'         => now()->addDays($ttl),
            'invited_by_user_id' => auth()->id(),
        ]);

        $url = url(route('register.withInvite', ['token' => $invite->token], false));

        // Try to email the customer the invite. We never let an SMTP
        // hiccup fail the request — the admin still gets the URL on
        // screen so they can copy/paste it manually.
        $emailed = false;
        if ($request->boolean('send_email')) {
            try {
                Mail::to($email)->send(new CustomerInviteMail(
                    invite:        $invite,
                    url:           $url,
                    invitedByName: auth()->user()?->name,
                ));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::warning('CustomerInviteMail send failed', [
                    'email'  => $email,
                    'invite' => $invite->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $status = $emailed
            ? "Invite created for {$email} and emailed to them."
            : "Invite created for {$email}.";

        return redirect()
            ->route('admin.invites')
            ->with('status', $status)
            ->with('invite_url', $url)
            ->with('invite_email', $email)
            ->with('invite_expires_at', $invite->expires_at->toDayDateTimeString())
            ->with('invite_emailed', $emailed);
    }
}
