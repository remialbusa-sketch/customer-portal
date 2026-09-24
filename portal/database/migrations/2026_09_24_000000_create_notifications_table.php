<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();          // recipient
            $table->string('type', 40);                     // claimable_ticket | ticket_status | tsr_sync_error | ticket_transfer
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('url')->nullable();              // deep link the bell navigates to
            $table->string('ticket_id')->nullable()->index(); // Monday ticket item id, for dedupe/filtering
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
