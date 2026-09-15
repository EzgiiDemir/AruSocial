<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_messages') || ! Schema::hasTable('messages')) {
            return;
        }

        $unmatched = [];
        $imported = 0;

        foreach (DB::table('chat_messages')->orderBy('sent_at')->orderBy('id')->get() as $row) {
            $fromMe = filter_var($row->from_me, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($fromMe === null) {
                $fromMe = in_array(strtolower((string) $row->from_me), ['1', 't', 'true', 'yes'], true);
            }
            $ownerId = (int) $row->user_id;
            $peer = $this->resolveUniqueUserId((string) $row->peer_name);

            if ($peer === null) {
                $unmatched[] = [
                    'id' => $row->id,
                    'user_id' => $ownerId,
                    'peer_name' => $row->peer_name,
                    'from_me' => $fromMe,
                    'reason' => 'peer_name did not uniquely match a user',
                ];

                continue;
            }

            $senderId = $fromMe ? $ownerId : $peer;
            $recipientId = $fromMe ? $peer : $ownerId;

            if ($senderId === $recipientId) {
                $unmatched[] = [
                    'id' => $row->id,
                    'user_id' => $ownerId,
                    'peer_name' => $row->peer_name,
                    'reason' => 'self_chat',
                ];

                continue;
            }

            $conversationId = $this->conversationIdFor($senderId, $recipientId);
            $already = DB::table('messages')->where('id', $row->id)->exists();
            if ($already) {
                continue;
            }

            $duplicate = DB::table('messages')
                ->where('conversation_id', $conversationId)
                ->where('sender_id', $senderId)
                ->where('body', $row->text)
                ->where('created_at', $row->sent_at)
                ->exists();
            if ($duplicate) {
                continue;
            }

            DB::table('messages')->insert([
                'id' => $row->id ?: 'msg-'.Str::uuid(),
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'body' => $row->text,
                'created_at' => $row->sent_at,
                'updated_at' => $row->sent_at,
            ]);
            $imported++;
        }

        $this->reportUnmatched($unmatched, $imported);
    }

    public function down(): void
    {
        if (Schema::hasTable('messages')) {
            DB::table('messages')->delete();
        }
        if (Schema::hasTable('conversation_participants')) {
            DB::table('conversation_participants')->delete();
        }
        if (Schema::hasTable('conversations')) {
            DB::table('conversations')->delete();
        }
    }

    private function resolveUniqueUserId(string $name): ?int
    {
        $matches = User::query()->where('name', $name)->get();
        if ($matches->count() !== 1) {
            return null;
        }

        return (int) $matches->first()->id;
    }

    private function conversationIdFor(int $a, int $b): int
    {
        $lo = min($a, $b);
        $hi = max($a, $b);
        $key = $lo.':'.$hi;
        $existing = DB::table('conversations')->where('pair_key', $key)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $now = now();
        $id = DB::table('conversations')->insertGetId([
            'pair_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([$lo, $hi] as $userId) {
            DB::table('conversation_participants')->insert([
                'conversation_id' => $id,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $id;
    }

    private function reportUnmatched(array $rows, int $imported): void
    {
        Log::info('chat_messages conversion finished', [
            'imported' => $imported,
            'skipped' => count($rows),
        ]);
        if ($rows === []) {
            return;
        }
        Log::warning('chat_messages conversion skipped rows that could not be mapped to a unique user pair', [
            'count' => count($rows),
            'rows' => $rows,
        ]);
        $path = storage_path('logs/chat_messages_unmatched.json');
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
};
