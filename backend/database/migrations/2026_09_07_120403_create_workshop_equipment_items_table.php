<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real replacement for the previously hardcoded, fake `_workshopEquipment`
// const shown on every workshop-category place in the Flutter map sheet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_equipment_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('place_id');
            $table->foreign('place_id')->references('id')->on('places')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_equipment_items');
    }
};
