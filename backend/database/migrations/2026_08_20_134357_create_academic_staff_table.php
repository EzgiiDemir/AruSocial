<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real ARUCAD personnel directory (docs/EKSIKLER.md aktivite/onay
        // workflow) — backs both the department→approver auto-routing
        // ("İlgili akademik personele yönlendirme") and the admin "E-posta"
        // recipient picker. `email_verified` is honest about data quality:
        // several people in the source roster have no confirmed email
        // ("doğrulama gerekli") — that's recorded, not silently guessed.
        Schema::create('academic_staff', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(true);
            // 'Akademik' | 'Yönetim' | 'Akademik/Yönetim' | 'Akademik/Yönetim (Mütevelli)'.
            $table->string('type');
            $table->string('faculty')->nullable();
            $table->string('department')->nullable();
            // Free-text real title, e.g. "Bölüm Başkanı", "Fakülte Dekanı",
            // "Rektör", "Rektör Yardımcısı", "Enstitü Müdürü".
            $table->string('title')->nullable();
            $table->boolean('is_department_head')->default(false);
            $table->boolean('is_faculty_dean')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_staff');
    }
};
