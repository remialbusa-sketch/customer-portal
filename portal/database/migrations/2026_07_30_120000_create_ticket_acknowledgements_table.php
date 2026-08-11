<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acknowledgements are first-touch confirmations that a TSP saw a
     * newly-created ticket in their email. They are separate from
     * "claim" (which writes the TSP's Monday person id to the People
     * column and flips response status to RESPONDED) — a TSP can
     * acknowledge a ticket without claiming it, signalling "I'm on
     * it, but I can't drive out right now" or "I'll send this to a
     * co-TSP in my region".
     *
     * Why a local table at all? The ticket itself only lives in
     * Monday.com — we don't mirror the row locally. But the
     * acknowledgement is a portal-side event (it happens when a TSP
     * clicks the link in their email), and we want a permanent local
     * audit trail of who acknowledged what and when. The Monday
     * column for this is a JSON long-text mirror we can write to
     * later if needed; the local table is the source of truth.
     *
     * The (monday_ticket_id, user_id) pair is unique so a single TSP
     * can't double-acknowledge the same ticket (re-clicking the
     * email link after they've already acknowledged is a no-op).
     */
    public function up(): void
    {
        Schema::create('ticket_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('monday_ticket_id', 32)->index()
                ->comment('Monday.com item id of the ticket that was acknowledged');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()
                ->comment('TSP who acknowledged');
            $table->string('ip', 45)->nullable()
                ->comment('IPv4 or IPv6, captured at acknowledge time');
            $table->string('user_agent', 512)->nullable()
                ->comment('User-Agent header, truncated to fit the column');
            $table->timestamp('acknowledged_at')->useCurrent();
            $table->timestamps();

            $table->unique(['monday_ticket_id', 'user_id'], 'ack_unique_per_user_per_ticket');
            $table->index(['user_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_acknowledgements');
    }
};
