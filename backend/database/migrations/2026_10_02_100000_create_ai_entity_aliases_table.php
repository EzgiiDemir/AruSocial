<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternative names students use for canonical campus entities.
 *
 * `crawl_sources.keys` and `page_keywords` describe crawled web pages; nothing
 * described the application's own rows, so "gym" could never reach the place
 * the database calls "Sports Center" unless someone edited a PHP array in
 * PlaceResolver. These rows feed entity resolution and routing directly —
 * they are never pasted into the prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_entity_aliases', function (Blueprint $table) {
            $table->id();
            // place | service | club | sport | food_venue | staff
            $table->string('entity_type', 32);
            // Every referenced table uses a string primary key.
            $table->string('entity_id', 191);
            $table->string('alias', 191);
            // TextFold of `alias`, maintained by the model; what matching uses.
            $table->string('normalized_alias', 191);
            // tr | en | ru, or null when the alias is language-neutral.
            $table->string('locale', 8)->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'normalized_alias']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('normalized_alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_entity_aliases');
    }
};
