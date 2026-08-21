<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->string('author_id')->nullable()->after('post_id');
        });

        Schema::create('post_shares', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('post_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_shares');
        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropColumn('author_id');
        });
    }
};
