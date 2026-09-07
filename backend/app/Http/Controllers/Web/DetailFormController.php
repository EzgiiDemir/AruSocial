<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ParticipationApplication;
use App\Services\ApplicationQuestionService;
use App\Services\ParticipationApplicationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

// The real, working page behind every application email's "detaylı formu
// aç" link — `/forms/application/{token}`. Deliberately outside `/api`
// and unauthenticated (a student may open this from any device, not
// necessarily signed into the app): the random 48-char `detail_form_token`
// IS the credential, so another user can never reach this application by
// guessing/incrementing an id. Category-specific questions come from
// ApplicationQuestionService — this controller never hardcodes a field
// list per target type.
class DetailFormController extends Controller
{
    public function show(string $token): View
    {
        $app = ParticipationApplication::with(['user'])->where('detail_form_token', $token)->first();
        if (! $app) {
            return view('forms.application-detail', [
                'valid' => false, 'app' => null, 'student' => null,
                'questions' => [], 'answers' => [], 'targetLabel' => null,
                'state' => 'invalid',
            ]);
        }

        $state = match (true) {
            in_array($app->status, [
                ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
                ParticipationApplication::STATUS_REVISION_REQUIRED,
            ], true) => 'fillable',
            in_array($app->status, [
                ParticipationApplication::STATUS_DETAIL_FORM_SUBMITTED,
                ParticipationApplication::STATUS_UNDER_REVIEW,
            ], true) => 'submitted',
            $app->status === ParticipationApplication::STATUS_APPROVED => 'approved',
            $app->status === ParticipationApplication::STATUS_REJECTED => 'rejected',
            default => 'submitted',
        };

        return view('forms.application-detail', [
            'valid' => true,
            'app' => $app,
            'student' => $app->user,
            'questions' => ApplicationQuestionService::forStage($app->target_type, 'detail'),
            'answers' => $app->detail_payload ?? [],
            'targetLabel' => ParticipationApplicationService::targetLabel($app),
            'reviewNote' => $app->status === ParticipationApplication::STATUS_REVISION_REQUIRED ? $app->review_note : null,
            'state' => $state,
        ]);
    }

    public function submit(Request $request, string $token): View
    {
        $app = ParticipationApplication::with(['user'])->where('detail_form_token', $token)->first();
        if (! $app || ! in_array($app->status, [
            ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
            ParticipationApplication::STATUS_REVISION_REQUIRED,
        ], true)) {
            return view('forms.application-detail', [
                'valid' => false, 'app' => null, 'student' => null,
                'questions' => [], 'answers' => [], 'targetLabel' => null,
                'state' => 'invalid',
            ]);
        }

        $questions = ApplicationQuestionService::forStage($app->target_type, 'detail');
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $request->input('q_'.$question->id);
        }

        $missing = ApplicationQuestionService::missingRequired($app->target_type, 'detail', $answers);
        if ($missing !== []) {
            return view('forms.application-detail', [
                'valid' => true,
                'app' => $app,
                'student' => $app->user,
                'questions' => $questions,
                'answers' => $answers,
                'targetLabel' => ParticipationApplicationService::targetLabel($app),
                'reviewNote' => $app->status === ParticipationApplication::STATUS_REVISION_REQUIRED ? $app->review_note : null,
                'state' => 'fillable',
                'error' => 'Zorunlu alanlar eksik: '.implode(', ', $missing),
            ]);
        }

        $student = $app->user;
        if ($student === null) {
            return view('forms.application-detail', [
                'valid' => false, 'app' => null, 'student' => null,
                'questions' => [], 'answers' => [], 'targetLabel' => null,
                'state' => 'invalid',
            ]);
        }
        ParticipationApplicationService::submitDetailForm($app, $answers, $student);

        return view('forms.application-detail', [
            'valid' => true, 'app' => $app->fresh(), 'student' => $student,
            'questions' => $questions, 'answers' => $answers,
            'targetLabel' => ParticipationApplicationService::targetLabel($app),
            'state' => 'submitted',
        ]);
    }
}
