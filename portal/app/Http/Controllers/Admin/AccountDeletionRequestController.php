<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin-only inbox for account-deletion requests.
 *
 * The customer/TSP-facing profile page is *not* allowed to delete
 * a user directly — instead, it files a row in
 * account_deletion_requests and the superadmin reviews each one
 * here. Approving actually deletes the user (and only the user —
 * we keep the request row for the audit log).
 *
 * Non-superadmin admins do not have access to these routes; the
 * route group is `role:superadmin`.
 */
class AccountDeletionRequestController extends Controller
{
    public function index(): View
    {
        $pending = AccountDeletionRequest::query()
            ->with('user')
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $recent = AccountDeletionRequest::query()
            ->with(['user', 'processor'])
            ->whereIn('status', [
                AccountDeletionRequest::STATUS_APPROVED,
                AccountDeletionRequest::STATUS_REJECTED,
            ])
            ->orderByDesc('processed_at')
            ->limit(25)
            ->get();

        return view('admin.deletion-requests', [
            'user'    => auth()->user(),
            'pending' => $pending,
            'recent'  => $recent,
        ]);
    }

    /**
     * Approve a request: mark it approved, then delete the user
     * inside a transaction. The request row stays for audit.
     */
    public function approve(Request $request, AccountDeletionRequest $deletionRequest): RedirectResponse
    {
        if (! $deletionRequest->isPending()) {
            return back()->withErrors([
                'request' => "This request has already been {$deletionRequest->status}.",
            ]);
        }

        $admin = $request->user();

        DB::transaction(function () use ($deletionRequest, $admin) {
            $deletionRequest->forceFill([
                'status'        => AccountDeletionRequest::STATUS_APPROVED,
                'processed_by'  => $admin->id,
                'processed_at'  => now(),
                'decision_note' => 'Approved by ' . $admin->name,
            ])->save();

            // Delete the user if they still exist. We do this
            // inside the same transaction so a failed delete
            // rolls back the status flip. Foreign keys on
            // chat_messages etc. are typically set to null —
            // adjust here if a schema uses restrict.
            if ($deletionRequest->user_id) {
                User::where('id', $deletionRequest->user_id)->delete();
            }
        });

        Log::info('account_deletion.approved', [
            'request_id' => $deletionRequest->id,
            'email'      => $deletionRequest->email,
            'admin_id'   => $admin->id,
        ]);

        return back()->with('status', "Request approved — account for {$deletionRequest->email} has been deleted.");
    }

    public function reject(Request $request, AccountDeletionRequest $deletionRequest): RedirectResponse
    {
        if (! $deletionRequest->isPending()) {
            return back()->withErrors([
                'request' => "This request has already been {$deletionRequest->status}.",
            ]);
        }

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        $deletionRequest->forceFill([
            'status'        => AccountDeletionRequest::STATUS_REJECTED,
            'processed_by'  => $request->user()->id,
            'processed_at'  => now(),
            'decision_note' => $data['decision_note'] ?? null,
        ])->save();

        Log::info('account_deletion.rejected', [
            'request_id' => $deletionRequest->id,
            'email'      => $deletionRequest->email,
            'admin_id'   => $request->user()->id,
        ]);

        return back()->with('status', "Request rejected — {$deletionRequest->email} will be notified.");
    }
}
