<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket-transfer requests between TSPs.
     *
     * When a TSP can't service a ticket they've claimed (unavailable,
     * off-region for the day, etc.), they can hand the assignment to
     * another TSP. The handoff is a two-step workflow:
     *
     *   1. The current assignee creates a PENDING request for a
     *      target TSP (this table).
     *   2. The target TSP must CONFIRM (accept) before anything is
     *      written to Monday.com — accepting replaces the People
     *      column (old TSP removed, new TSP added). Declining or
     *      cancelling leaves the assignment untouched.
     *
     * Status lifecycle: pending → accepted | declined | cancelled.
     * Only one pending transfer per (ticket, target) is allowed so a
     * spammed "Transfer" button can't stack duplicate requests.
     */
    public function up(): void
    {
        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('monday_ticket_id', 32)->index()
                ->comment('Monday.com item id of the ticket being handed over');
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete()
                ->comment('TSP who currently holds the assignment and requested the transfer');
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete()
                ->comment('TSP the assignment is offered to; only they can accept');
            $table->string('status', 16)->default('pending')
                ->comment('pending | accepted | declined | cancelled');
            $table->timestamp('resolved_at')->nullable()
                ->comment('When the request reached a final state (accepted/declined/cancelled)');
            $table->timestamps();

            $table->unique(['monday_ticket_id', 'to_user_id', 'status'], 'one_pending_transfer_per_target')
                ->comment('At most one pending request per ticket+target pair');
            $table->index(['to_user_id', 'status']);
            $table->index(['from_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_transfers');
    }
};
