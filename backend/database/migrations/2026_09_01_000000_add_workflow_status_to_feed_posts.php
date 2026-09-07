<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->string('workflow_status')->default('published')->after('official');
            $table->text('review_note')->nullable()->after('workflow_status');
            $table->index('workflow_status');
        });
    }

    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropIndex(['workflow_status']);
            $table->dropColumn(['workflow_status', 'review_note']);
        });
    }
};
