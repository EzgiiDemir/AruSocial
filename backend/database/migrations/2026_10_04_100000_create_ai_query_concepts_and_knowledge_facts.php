<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two small retrieval layers found missing by the evaluation suite.
 *
 * ai_query_concepts: the middle layer between a student's wording and the
 * sources. "dersler ne zaman başlıyor" shares no word with the academic
 * calendar page; a concept maps the wording to target domains and retrieval
 * terms. Concepts improve routing and retrieval only — never answers.
 *
 * knowledge_facts: stable facts extracted from a reliable page structure
 * (the admissions sites' programme field block "Eğitim Dili … Eğitim
 * Süresi …"), each with its source URL, passage and verification time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_query_concepts', function (Blueprint $table) {
            $table->id();
            $table->string('concept', 64)->unique();
            $table->string('label', 191)->nullable();
            // Wordings in any of the app's languages; matched folded, as whole phrases.
            $table->json('phrases');
            $table->json('domains')->nullable();
            // Words added to keyword retrieval when the concept matches.
            $table->json('retrieval_terms')->nullable();
            // URL path fragments of the pages this concept is about (e.g. "rt-program").
            $table->json('preferred_paths')->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_facts', function (Blueprint $table) {
            $table->id();
            $table->string('knowledge_document_id', 64);
            // e.g. "programme"
            $table->string('subject_type', 32);
            $table->string('subject', 191);
            $table->string('subject_folded', 191);
            // language_of_instruction | duration
            $table->string('attribute', 64);
            $table->string('value', 191);
            $table->string('source_url', 700);
            $table->text('source_passage');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_document_id', 'attribute']);
            $table->index(['subject_type', 'subject_folded']);
        });

        $now = now();
        $concepts = [
            [
                'concept' => 'academic_calendar', 'label' => 'Academic calendar / term start',
                'phrases' => ['akademik takvim', 'dersler ne zaman', 'ders ne zaman', 'derslerin baslamasi', 'ders baslangici',
                    'donem ne zaman', 'okul ne zaman aciliyor', 'yariyil baslangici', 'academic calendar', 'term dates',
                    'when do classes start', 'when does the semester start', 'when does the term start', 'semester start',
                    'академический календарь', 'начало занятий', 'когда начинаются занятия', 'когда начинается семестр'],
                'domains' => ['calendar'],
                'retrieval_terms' => ['akademik takvim', 'academic calendar'],
                'preferred_paths' => ['akademik-takvim', 'academic-calendar'],
            ],
            [
                'concept' => 'language_of_instruction', 'label' => 'Language of instruction',
                'phrases' => ['egitim dili', 'ogretim dili', 'hangi dilde', 'language of instruction', 'medium of instruction',
                    'taught in english', 'taught in turkish', 'teaching language', 'язык обучения', 'на каком языке'],
                'domains' => ['programs', 'knowledge'],
                'retrieval_terms' => ['egitim dili', 'language of instruction'],
                'preferred_paths' => ['rt-program', 'rt-faculty'],
            ],
            [
                'concept' => 'programme_duration', 'label' => 'Programme duration',
                'phrases' => ['egitim suresi', 'kac yil', 'kac yillik', 'how many years', 'programme duration', 'program duration',
                    'сколько лет', 'продолжительность обучения'],
                'domains' => ['programs', 'knowledge'],
                'retrieval_terms' => ['egitim suresi', 'duration'],
                'preferred_paths' => ['rt-program'],
            ],
        ];
        foreach ($concepts as $concept) {
            DB::table('ai_query_concepts')->insert([
                'concept' => $concept['concept'],
                'label' => $concept['label'],
                'phrases' => json_encode($concept['phrases'], JSON_UNESCAPED_UNICODE),
                'domains' => json_encode($concept['domains']),
                'retrieval_terms' => json_encode($concept['retrieval_terms'], JSON_UNESCAPED_UNICODE),
                'preferred_paths' => json_encode($concept['preferred_paths']),
                'active' => true,
                'created_by' => 'migration',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_facts');
        Schema::dropIfExists('ai_query_concepts');
    }
};
