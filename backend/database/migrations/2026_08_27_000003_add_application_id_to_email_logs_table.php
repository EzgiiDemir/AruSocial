<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links an email_logs row back to the ParticipationApplication it
        // was sent for, so the admin/trainer Applications panel can show a
        // real "e-posta gönderildi mi?" status per application instead of
        // only knowing that *some* email exists somewhere in the log.
        // Nullable: most email_logs rows (moderation alerts, digests, ...)
        // have nothing to do with an application.
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('application_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn('application_id');
        });
    }
};
