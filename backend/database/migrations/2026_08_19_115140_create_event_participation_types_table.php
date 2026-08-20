<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (event, participation type) an admin defines — the
        // "Katılımcı / Gönüllü / Organizasyon / Görevli" ask from
        // docs/GERCEK_PROJEYE_GECIS.md §5. Schema support only for now —
        // the Flutter join-event UI doesn't let a student pick one yet.
        Schema::create('event_participation_types', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('event_id');
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participation_types');
    }
};
