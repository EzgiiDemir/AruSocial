<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who accepted which version of the policy, and when.
 *
 * Until now acceptance was a boolean in the phone's own SharedPreferences.
 * That records that *a device* dismissed a screen — not that a person
 * agreed to anything. It cannot answer "did this student accept the
 * moderation notice", it is lost when the app is reinstalled, and it has no
 * version, so an updated policy would never be re-offered.
 *
 * One row per (user, document, version). Accepting again is a no-op rather
 * than a second row, and a new version is a new row — so the table is the
 * history, not just the current state.
 *
 * Deliberately no IP address or device fingerprint. The requirement is to
 * record *who*, *when* and *which version*; the user id already answers
 * "who", and collecting a network address on top would be extra personal
 * data held forever for no purpose the consent record needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_consents', function (Blueprint $table) {
            $table->id();

            // Cascades: deleting an account erases the person's data, and a
            // consent record naming a user who no longer exists is exactly
            // the orphan that erasure is supposed to remove.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('document', 40);
            $table->string('version', 64);

            // Which translation was actually shown. If the wording of one
            // language later turns out to differ in meaning, this is the
            // only way to know who read which text.
            $table->string('locale', 8);

            $table->timestamp('accepted_at');

            $table->unique(['user_id', 'document', 'version']);
            $table->index(['document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_consents');
    }
};
