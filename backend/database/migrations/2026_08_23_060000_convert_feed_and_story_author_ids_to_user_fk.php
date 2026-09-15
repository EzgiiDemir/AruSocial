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
        $this->convertAuthorIdToUserFk('feed_posts', 'feed_posts_unmatched.json');
        $this->convertAuthorIdToUserFk('stories', 'stories_unmatched.json');
    }

    public function down(): void
    {
        $this->revertAuthorIdToString('feed_posts');
        $this->revertAuthorIdToString('stories');
    }

    private function convertAuthorIdToUserFk(string $table, string $logFile): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'author_id')) {
            return;
        }

        if ($this->authorIdIsInteger($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('author_id_fk')->nullable();
        });

        $unmatched = [];
        $mapped = 0;

        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $resolved = $this->resolveUserId((string) $row->author_id);
            DB::table($table)->where('id', $row->id)->update(['author_id_fk' => $resolved]);
            if ($resolved === null) {
                $unmatched[] = [
                    'id' => $row->id,
                    'author_id' => $row->author_id,
                    'reason' => 'author_id did not match an existing users.id',
                ];

                continue;
            }
            $mapped++;
        }

        $this->reportUnmatched($table, $logFile, $unmatched, $mapped);

        Schema::disableForeignKeyConstraints();
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('author_id');
        });
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->renameColumn('author_id_fk', 'author_id');
        });
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::enableForeignKeyConstraints();
    }

    private function revertAuthorIdToString(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'author_id')) {
            return;
        }
        if (! $this->authorIdIsInteger($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('author_id_str')->default('');
        });

        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            DB::table($table)->where('id', $row->id)->update([
                'author_id_str' => $row->author_id === null ? '' : (string) $row->author_id,
            ]);
        }

        Schema::disableForeignKeyConstraints();
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropForeign(['author_id']);
        });
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('author_id');
        });
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->renameColumn('author_id_str', 'author_id');
        });
        Schema::enableForeignKeyConstraints();
    }

    // Legacy writers stored (string) users.id, never a display name. Only
    // a digit string that matches an existing user is kept as ownership;
    // anything else becomes null so the row is not deleted or reassigned.
    private function resolveUserId(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        if ($id <= 0 || ! DB::table('users')->where('id', $id)->exists()) {
            return null;
        }

        return $id;
    }

    private function authorIdIsInteger(string $table): bool
    {
        $type = Schema::getColumnType($table, 'author_id');

        return in_array(strtolower((string) $type), ['bigint', 'integer', 'int', 'int8', 'int4', 'smallint', 'int2'], true);
    }

    private function reportUnmatched(string $table, string $logFile, array $rows, int $mapped): void
    {
        Log::info("$table author_id conversion finished", [
            'mapped' => $mapped,
            'unmatched' => count($rows),
        ]);
        if ($rows === []) {
            return;
        }

        Log::warning("$table conversion left rows with null author_id", [
            'count' => count($rows),
            'rows' => $rows,
        ]);
        $path = storage_path('logs/'.$logFile);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
};
