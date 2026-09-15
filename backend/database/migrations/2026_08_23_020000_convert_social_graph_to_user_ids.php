<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->convertFollowsToUserIds();
        $this->convertBlocksToUserIds();
    }

    public function down(): void
    {
        $this->restoreFollowsToNames();
        $this->restoreBlocksToNames();
    }

    private function convertFollowsToUserIds(): void
    {
        if (! Schema::hasTable('social_follows')) {
            return;
        }
        if (Schema::hasColumn('social_follows', 'followed_user_id')
            && ! Schema::hasColumn('social_follows', 'followed_name')) {
            return;
        }

        Schema::create('social_follows_by_id', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['follower_user_id', 'followed_user_id']);
        });

        $unmatched = [];
        $seen = [];
        foreach (DB::table('social_follows')->orderBy('id')->get() as $row) {
            $matches = DB::table('users')->where('name', $row->followed_name)->get();
            if ($matches->count() !== 1) {
                $unmatched[] = [
                    'id' => $row->id,
                    'follower_user_id' => $row->follower_user_id,
                    'followed_name' => $row->followed_name,
                    'reason' => $matches->isEmpty() ? 'no_user' : 'ambiguous_name',
                ];

                continue;
            }
            $targetId = $matches[0]->id;
            if ((int) $row->follower_user_id === (int) $targetId) {
                $unmatched[] = [
                    'id' => $row->id,
                    'follower_user_id' => $row->follower_user_id,
                    'followed_name' => $row->followed_name,
                    'reason' => 'self_follow',
                ];

                continue;
            }
            $key = $row->follower_user_id.'-'.$targetId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            DB::table('social_follows_by_id')->insert([
                'follower_user_id' => $row->follower_user_id,
                'followed_user_id' => $targetId,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);
        }
        $this->reportUnmatched('social_follows', $unmatched);

        Schema::disableForeignKeyConstraints();
        Schema::drop('social_follows');
        Schema::rename('social_follows_by_id', 'social_follows');
        Schema::enableForeignKeyConstraints();
    }

    private function convertBlocksToUserIds(): void
    {
        if (! Schema::hasTable('social_blocks')) {
            return;
        }
        if (Schema::hasColumn('social_blocks', 'blocked_user_id')
            && ! Schema::hasColumn('social_blocks', 'blocked_name')) {
            return;
        }

        Schema::create('social_blocks_by_id', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['blocker_user_id', 'blocked_user_id']);
        });

        $unmatched = [];
        $seen = [];
        foreach (DB::table('social_blocks')->orderBy('id')->get() as $row) {
            $matches = DB::table('users')->where('name', $row->blocked_name)->get();
            if ($matches->count() !== 1) {
                $unmatched[] = [
                    'id' => $row->id,
                    'blocker_user_id' => $row->blocker_user_id,
                    'blocked_name' => $row->blocked_name,
                    'reason' => $matches->isEmpty() ? 'no_user' : 'ambiguous_name',
                ];

                continue;
            }
            $targetId = $matches[0]->id;
            if ((int) $row->blocker_user_id === (int) $targetId) {
                $unmatched[] = [
                    'id' => $row->id,
                    'blocker_user_id' => $row->blocker_user_id,
                    'blocked_name' => $row->blocked_name,
                    'reason' => 'self_block',
                ];

                continue;
            }
            $key = $row->blocker_user_id.'-'.$targetId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            DB::table('social_blocks_by_id')->insert([
                'blocker_user_id' => $row->blocker_user_id,
                'blocked_user_id' => $targetId,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);
        }
        $this->reportUnmatched('social_blocks', $unmatched);

        Schema::disableForeignKeyConstraints();
        Schema::drop('social_blocks');
        Schema::rename('social_blocks_by_id', 'social_blocks');
        Schema::enableForeignKeyConstraints();
    }

    private function restoreFollowsToNames(): void
    {
        if (! Schema::hasTable('social_follows') || Schema::hasColumn('social_follows', 'followed_name')) {
            return;
        }

        Schema::create('social_follows_by_name', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('followed_name');
            $table->timestamp('created_at');
            $table->unique(['follower_user_id', 'followed_name']);
        });

        foreach (DB::table('social_follows')->orderBy('id')->get() as $row) {
            $name = DB::table('users')->where('id', $row->followed_user_id)->value('name');
            if ($name === null) {
                continue;
            }
            DB::table('social_follows_by_name')->insert([
                'follower_user_id' => $row->follower_user_id,
                'followed_name' => $name,
                'created_at' => $row->created_at,
            ]);
        }

        Schema::disableForeignKeyConstraints();
        Schema::drop('social_follows');
        Schema::rename('social_follows_by_name', 'social_follows');
        Schema::enableForeignKeyConstraints();
    }

    private function restoreBlocksToNames(): void
    {
        if (! Schema::hasTable('social_blocks') || Schema::hasColumn('social_blocks', 'blocked_name')) {
            return;
        }

        Schema::create('social_blocks_by_name', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('blocked_name');
            $table->timestamp('created_at');
            $table->unique(['blocker_user_id', 'blocked_name']);
        });

        foreach (DB::table('social_blocks')->orderBy('id')->get() as $row) {
            $name = DB::table('users')->where('id', $row->blocked_user_id)->value('name');
            if ($name === null) {
                continue;
            }
            DB::table('social_blocks_by_name')->insert([
                'blocker_user_id' => $row->blocker_user_id,
                'blocked_name' => $name,
                'created_at' => $row->created_at,
            ]);
        }

        Schema::disableForeignKeyConstraints();
        Schema::drop('social_blocks');
        Schema::rename('social_blocks_by_name', 'social_blocks');
        Schema::enableForeignKeyConstraints();
    }

    private function reportUnmatched(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        Log::warning("$table conversion skipped rows that could not be mapped to a unique user", [
            'table' => $table,
            'count' => count($rows),
            'rows' => $rows,
        ]);

        $path = storage_path("logs/{$table}_unmatched.json");
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
};
