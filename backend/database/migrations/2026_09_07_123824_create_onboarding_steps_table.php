<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Real replacement for the previously fully-hardcoded `onboardingSteps`
// const in `onboarding_config.dart` — only per-user *completion* lived in
// the database (`onboarding_progress`) before this; the checklist content
// itself (title/detail/grouping/ordering) had no admin-editable home.
//
// Seeded with the exact same 13 steps/ids the Dart const already shipped,
// so existing `onboarding_progress.step_id` rows (and the mock-mode const,
// which stays as an offline fallback) keep resolving to a real step.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_steps', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('group_label');
            $table->string('title');
            $table->text('detail');
            $table->string('action_kind')->default('info');
            $table->string('ref_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $steps = [
            ['id' => 'day1-orientation', 'group_label' => '1. Gün', 'title' => 'Kampüs oryantasyonuna katıl', 'detail' => 'Yeni öğrenciler için düzenlenen oryantasyon, kampüsün genel işleyişini, binaları ve ilk haftada kimden ne isteyeceğini anlatır. Tarih ve yer için Öğrenci İşleri ile iletişime geç.', 'action_kind' => 'service', 'ref_id' => 'student-affairs', 'sort_order' => 0],
            ['id' => 'day1-affairs', 'group_label' => '1. Gün', 'title' => 'Öğrenci İşleri ile tanış', 'detail' => 'Kayıt, ders seçimi, resmi belgeler ve mezuniyete kadar her idari süreçte ilk başvuracağın yer burası.', 'action_kind' => 'service', 'ref_id' => 'student-affairs', 'sort_order' => 1],
            ['id' => 'day1-id', 'group_label' => '1. Gün', 'title' => 'Öğrenci kimlik kartını al', 'detail' => "Kimlik kartı kütüphane, atölye ve etkinlik girişleri için gerekli — Öğrenci İşleri üzerinden başvuruyorsun.", 'action_kind' => 'service', 'ref_id' => 'student-affairs', 'sort_order' => 2],
            ['id' => 'week1-department', 'group_label' => '1. Hafta', 'title' => 'Bölümünü ve binanı bul', 'detail' => "Derslerinin ve atölyelerinin nerede olduğunu bilmek ilk haftanın en pratik kazanımı — Bina Dizini'nden gerçek konum bilgisine bakabilirsin.", 'action_kind' => 'info', 'ref_id' => null, 'sort_order' => 3],
            ['id' => 'week1-advisor', 'group_label' => '1. Hafta', 'title' => 'Akademik danışmanınla tanış', 'detail' => 'Her öğrencinin bölümünden bir akademik danışmanı vardır; ders seçimi ve akademik plan için ona başvurursun.', 'action_kind' => 'service', 'ref_id' => 'academic-advising', 'sort_order' => 4],
            ['id' => 'week1-club', 'group_label' => '1. Hafta', 'title' => 'Bir kulübe göz at', 'detail' => "ARUCAD'da onlarca gerçek öğrenci kulübü var — fotoğraftan e-spora, dansa kadar. Birine göz atmak katılmayı garantilemez, ama başlangıç için iyi.", 'action_kind' => 'list', 'ref_id' => 'clubs', 'sort_order' => 5],
            ['id' => 'week1-garden', 'group_label' => '1. Hafta', 'title' => "Garden'ı keşfet", 'detail' => 'Kampüsün açık hava sosyal alanı — molalarda, sohbette ve bazı etkinliklerde buluşma noktası. Discover → Yerler üzerinden bulabilirsin.', 'action_kind' => 'info', 'ref_id' => null, 'sort_order' => 6],
            ['id' => 'week2-library', 'group_label' => '2. Hafta', 'title' => 'Kütüphaneyi ziyaret et', 'detail' => 'Sanat, tasarım ve iletişim odaklı kaynaklar, sessiz çalışma alanları ve dijital istasyonlar burada.', 'action_kind' => 'service', 'ref_id' => 'library', 'sort_order' => 7],
            ['id' => 'week2-sports', 'group_label' => '2. Hafta', 'title' => 'Bir spor etkinliğine katıl', 'detail' => "Basketboldan bowling'e kadar gerçek ARUCAD spor imkânları var — hangilerinin olduğuna bak.", 'action_kind' => 'list', 'ref_id' => 'sports', 'sort_order' => 8],
            ['id' => 'week2-social', 'group_label' => '2. Hafta', 'title' => 'Bir sosyal etkinliğe git', 'detail' => 'Home\'daki "Bugün" ve Sosyal sekmesindeki "Kampüste Şimdi" bölümleri güncel etkinlikleri gösterir.', 'action_kind' => 'info', 'ref_id' => null, 'sort_order' => 9],
            ['id' => 'week3-kyrenia', 'group_label' => '3. Hafta', 'title' => "Girne'yi keşfet", 'detail' => 'Kampüsün bulunduğu şehri tanımak da kampüs yaşamının bir parçası — bu adım için uygulama içinde tek bir gerçek hedef yok, kendi keşfin.', 'action_kind' => 'info', 'ref_id' => null, 'sort_order' => 10],
            ['id' => 'week3-community', 'group_label' => '3. Hafta', 'title' => 'Bir topluluğa katıl', 'detail' => 'Kulüplerden birine gerçekten katılmak (sadece göz atmak değil) — listeye bakıp "Katıl"a dokunabilirsin.', 'action_kind' => 'list', 'ref_id' => 'clubs', 'sort_order' => 11],
            ['id' => 'week4-career', 'group_label' => '4. Hafta', 'title' => 'Kariyer ofisiyle tanış', 'detail' => 'İş/staj fırsatları, kariyer etkinlikleri, portfolyo değerlendirmesi ve mezun ağı bağlantıları — ne zaman ihtiyacın olursa bilmen için şimdiden tanış.', 'action_kind' => 'service', 'ref_id' => 'career', 'sort_order' => 12],
        ];
        foreach ($steps as &$step) {
            $step['active'] = true;
            $step['created_at'] = $now;
            $step['updated_at'] = $now;
        }
        DB::table('onboarding_steps')->insert($steps);
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_steps');
    }
};
