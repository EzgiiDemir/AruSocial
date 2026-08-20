<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real log of every send attempt through Laravel's own Mail
        // facade — status/error are genuine (whatever the mailer driver
        // actually returned), not simulated. With no real SMTP configured
        // (see docs/EXTERNAL_ACCOUNTS.md §5) MAIL_MAILER defaults to Laravel's
        // "log" driver, so sends succeed and land in storage/logs instead
        // of a real inbox — status here reflects that honestly too.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('to_email');
            $table->string('subject');
            $table->string('template');
            $table->string('status'); // sent | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
