<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Formalizes the two-stage apply flow every "Katıl/Başvur" action must
// follow: a short Preview form (existing `form_payload`, renamed in
// meaning — never in column name, to avoid a data migration — to "preview
// answers") gets the student a secure, single-use link to a category-
// specific Detail form, and only a real Detail form + staff approval
// creates real participation. `form_payload` keeps its column name for
// backward compatibility with already-submitted rows; the model exposes
// it as `previewPayload`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participation_applications', function (Blueprint $table) {
            $table->json('detail_payload')->nullable()->after('form_payload');
            $table->string('detail_form_token', 64)->nullable()->unique()->after('detail_payload');
            $table->timestamp('detail_form_submitted_at')->nullable()->after('detail_form_token');
        });
    }

    public function down(): void
    {
        Schema::table('participation_applications', function (Blueprint $table) {
            $table->dropColumn(['detail_payload', 'detail_form_token', 'detail_form_submitted_at']);
        });
    }
};
