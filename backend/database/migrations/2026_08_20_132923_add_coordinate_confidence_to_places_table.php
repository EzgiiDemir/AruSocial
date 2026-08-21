<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real POI coordinate provenance (docs/EKSIKLER.md — harita/POI):
        // most of the real ARUCAD POI set below comes from precise 6-decimal
        // coordinates, but a few (Arkin Rodin Collection Gallery, and the
        // Age of Bronze / Art Rooms / Iris workshop-building cluster) share
        // identical or address-derived coordinates that need real on-site
        // verification — this field says so honestly instead of presenting
        // every point as equally precise.
        Schema::table('places', function (Blueprint $table) {
            $table->string('coordinate_confidence')->default('verified')->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('coordinate_confidence');
        });
    }
};
