<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertAthleteRequest;
use App\Http\Resources\AthleteResource;
use App\Services\Planning\Replanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AthleteController extends Controller
{
    public function show(Request $request): AthleteResource
    {
        return new AthleteResource($request->user()->athleteOrFail());
    }

    /**
     * Profile fields the plan is built from; changing one re-plans the active plan.
     */
    private const array PLAN_INPUTS = ['experience', 'weekly_hours', 'weakest_sport', 'birth_year', 'prefs'];

    public function upsert(UpsertAthleteRequest $request, Replanner $replanner): JsonResponse
    {
        $user = $request->user();
        $athlete = $user->athlete ?? $user->athlete()->make();
        $created = ! $athlete->exists;

        $athlete->fill($request->safe()->except('preferences'));
        $athlete->prefs = $request->mergedPreferences();
        $athlete->save();

        if (! $created && $athlete->wasChanged(self::PLAN_INPUTS)) {
            $replanner->inputsChanged($athlete, 'Re-planned for your updated training profile.');
        }

        return (new AthleteResource($athlete))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
