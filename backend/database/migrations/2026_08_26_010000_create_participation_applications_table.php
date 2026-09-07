<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hardening-2: unified participation applications for club/sport/service/
// career/community/help — pending → approve/reject with form payload.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participation_applications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type'); // club|sport|service|career|community|help
            $table->string('target_id');
            $table->string('status')->default('submitted'); // submitted|under_review|approved|rejected|cancelled
            $table->string('responsible_staff_id')->nullable();
            $table->json('form_payload')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['target_type', 'target_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_applications');
    }
};
