<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status')->default('active')->after('preferred_language');
            $table->string('phone')->nullable()->after('account_status');
            $table->string('institution_id')->nullable()->after('phone');
            $table->string('job_title')->nullable()->after('institution_id');
            $table->string('timezone')->default('Europe/Nicosia')->after('job_title');
            $table->string('campus')->nullable()->after('timezone');
            $table->string('faculty')->nullable()->after('campus');
            $table->string('unit')->nullable()->after('faculty');
            $table->string('building')->nullable()->after('unit');
            $table->boolean('mfa_required')->default(false)->after('building');
        });

        Schema::create('role_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->boolean('is_primary')->default(false);
            $table->string('scope_type')->default('all');
            $table->string('scope_id')->nullable();
            $table->json('permissions')->nullable();
            $table->json('denied_permissions')->nullable();
            $table->boolean('can_publish')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('sensitive_data_access')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('assigned_by');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_at', 'expires_at']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_grants');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'account_status', 'phone', 'institution_id', 'job_title', 'timezone',
                'campus', 'faculty', 'unit', 'building', 'mfa_required',
            ]);
        });
    }
};
