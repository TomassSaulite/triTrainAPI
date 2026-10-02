<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AthleteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\LoadController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlannedWorkoutController;
use App\Http\Controllers\Api\RaceController;
use App\Http\Controllers\Api\RaceStrategyController;
use App\Http\Controllers\Api\SessionFeedbackController;
use App\Http\Controllers\Api\StravaConnectionController;
use App\Http\Controllers\Api\ThresholdController;
use App\Http\Controllers\Api\ThresholdSuggestionController;
use App\Http\Controllers\Api\WorkoutTemplateController;
use App\Http\Controllers\StravaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['name' => config('app.name'), 'version' => 'v1']);

Route::prefix('auth')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::get('calendar/feed/{token}.ics', [CalendarFeedController::class, 'feed'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('calendar.feed');

Route::get('strava/callback', [StravaConnectionController::class, 'callback'])->name('strava.callback');
Route::get('webhooks/strava', [StravaWebhookController::class, 'verify']);
Route::post('webhooks/strava', [StravaWebhookController::class, 'receive']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [AuthController::class, 'me']);

    Route::get('athlete', [AthleteController::class, 'show']);
    Route::put('athlete', [AthleteController::class, 'upsert']);

    Route::get('thresholds/current', [ThresholdController::class, 'current']);
    Route::get('thresholds/status', [ThresholdController::class, 'status']);
    Route::apiResource('thresholds', ThresholdController::class)->only(['index', 'store', 'destroy']);

    Route::get('threshold-suggestions', [ThresholdSuggestionController::class, 'index']);
    Route::post('threshold-suggestions/{thresholdSuggestion}/accept', [ThresholdSuggestionController::class, 'accept']);
    Route::post('threshold-suggestions/{thresholdSuggestion}/dismiss', [ThresholdSuggestionController::class, 'dismiss']);

    Route::apiResource('races', RaceController::class);
    Route::post('races/{race}/plan', [PlanController::class, 'store']);
    Route::get('races/{race}/strategy', RaceStrategyController::class);

    Route::get('plans/current', [PlanController::class, 'current']);
    Route::get('plans', [PlanController::class, 'index']);
    Route::get('plans/{plan}', [PlanController::class, 'show']);
    Route::post('plans/{plan}/regenerate', [PlanController::class, 'regenerate']);
    Route::post('plans/{plan}/archive', [PlanController::class, 'archive']);
    Route::get('plans/{plan}/revisions', [PlanController::class, 'revisions']);
    Route::get('plans/{plan}/progress', [PlanController::class, 'progress']);
    Route::get('plans/{plan}/weekly-review', [PlanController::class, 'weeklyReview']);

    Route::get('calendar', CalendarController::class);
    Route::get('calendar-feed', [CalendarFeedController::class, 'show']);
    Route::post('calendar-feed', [CalendarFeedController::class, 'store']);
    Route::delete('calendar-feed', [CalendarFeedController::class, 'destroy']);

    Route::get('strava', [StravaConnectionController::class, 'show']);
    Route::post('strava/connect', [StravaConnectionController::class, 'connect']);
    Route::delete('strava', [StravaConnectionController::class, 'destroy']);

    Route::get('planned-workouts/{plannedWorkout}', [PlannedWorkoutController::class, 'show']);
    Route::patch('planned-workouts/{plannedWorkout}', [PlannedWorkoutController::class, 'update']);
    Route::post('planned-workouts/{plannedWorkout}/skip', [PlannedWorkoutController::class, 'skip']);
    Route::get('planned-workouts/{plannedWorkout}/alternatives', [PlannedWorkoutController::class, 'alternatives']);
    Route::post('planned-workouts/{plannedWorkout}/swap', [PlannedWorkoutController::class, 'swap']);
    Route::get('planned-workouts/{plannedWorkout}/export', [PlannedWorkoutController::class, 'export']);

    Route::get('availability', [AvailabilityController::class, 'index']);
    Route::post('availability/break', [AvailabilityController::class, 'storeBreak']);
    Route::put('availability/{date}', [AvailabilityController::class, 'upsert']);
    Route::delete('availability/{date}', [AvailabilityController::class, 'destroy']);

    Route::post('activities/import', [ActivityController::class, 'import']);
    Route::apiResource('activities', ActivityController::class);
    Route::put('activities/{activity}/feedback', [SessionFeedbackController::class, 'upsert']);
    Route::delete('activities/{activity}/feedback', [SessionFeedbackController::class, 'destroy']);
    Route::get('feedback', [SessionFeedbackController::class, 'index']);

    Route::get('load', [LoadController::class, 'index']);
    Route::get('load/summary', [LoadController::class, 'summary']);

    Route::apiResource('workout-templates', WorkoutTemplateController::class);
});
