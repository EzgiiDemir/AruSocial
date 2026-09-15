<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_comments') || ! Schema::hasColumn('post_comments', 'author')) {
            return;
        }

        $unmatched = [];
        $mapped = 0;

        foreach (DB::table('post_comments')->orderBy('id')->get() as $row) {
            $userId = $this->resolveUniqueUserId((string) $row->author);
            if ($userId === null) {
                $unmatched[] = [
                    'id' => $row->id,
                    'post_id' => $row->post_id,
                    'author' => $row->author,
                    'reason' => 'author did not uniquely match a user',
                ];

                continue;
            }

            DB::table('post_comments')->where('id', $row->id)->update(['user_id' => $userId]);
            $mapped++;
        }

        $this->reportUnmatched($unmatched, $mapped);

        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropColumn('author');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('post_comments') || Schema::hasColumn('post_comments', 'author')) {
            return;
        }

        Schema::table('post_comments', function (Blueprint $table) {
            $table->string('author')->default('');
        });

        foreach (DB::table('post_comments')->orderBy('id')->get() as $row) {
            $name = $row->user_id
                ? (string) (DB::table('users')->where('id', $row->user_id)->value('name') ?? '')
                : '';
            DB::table('post_comments')->where('id', $row->id)->update(['author' => $name]);
        }
    }

    private function resolveUniqueUserId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $matches = User::query()->where('name', $name)->get();
        if ($matches->count() !== 1) {
            return null;
        }

        return (int) $matches->first()->id;
    }

    private function reportUnmatched(array $rows, int $mapped): void
    {
        Log::info('post_comments author conversion finished', [
            'mapped' => $mapped,
            'skipped' => count($rows),
        ]);
        if ($rows === []) {
            return;
        }

        Log::warning('post_comments conversion skipped rows that could not be mapped to a unique user', [
            'count' => count($rows),
            'rows' => $rows,
        ]);
        $path = storage_path('logs/post_comments_unmatched.json');
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
};
