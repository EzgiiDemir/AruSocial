<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routing keywords for each crawl source.
 *
 * Retrieval currently scores every indexed document on every question: a scan
 * of the corpus in PHP, plus a semantic comparison. That is affordable at a
 * few hundred pages and it is the part that grows worst as the corpus does.
 *
 * These keys are a cheap hint in front of that. An operator writes the words a
 * source is *about* — "burs, ücret, başvuru, scholarship, tuition" against
 * aday.arucad.edu.tr — and a question containing one of them lifts that
 * domain's pages instead of the system having to discover the association from
 * the text every time. Matching a handful of short strings costs nothing next
 * to scoring several hundred documents.
 *
 * Deliberately a hint and not a filter. A source with no keys is not excluded,
 * and a question that matches no key still gets the full ranking — otherwise a
 * forgotten keyword would silently make part of the corpus unreachable, which
 * is a far worse failure than a slightly slower query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_sources', function (Blueprint $table) {
            // Free text, comma-separated. Not a relation: these are a handful
            // of words per row, edited by hand in the admin panel, and a join
            // table would add a query to the hot path this exists to shorten.
            $table->text('keys')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_sources', function (Blueprint $table) {
            $table->dropColumn('keys');
        });
    }
};
