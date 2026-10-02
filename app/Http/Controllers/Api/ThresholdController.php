<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreThresholdRequest;
use App\Http\Resources\ThresholdResource;
use App\Models\Threshold;
use App\Services\ThresholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Threshold history is append-only: a new test adds a row and the newest row
 * per metric is the one in use. Mistaken entries can be deleted, not edited.
 */
class ThresholdController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'metric' => ['sometimes', Rule::enum(ThresholdMetric::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        $thresholds = $request->user()->athleteOrFail()->thresholds()
            ->when($request->query('metric'), fn ($q, $metric) => $q->where('metric', $metric))
            ->orderByDesc('tested_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', '15'));

        return ThresholdResource::collection($thresholds);
    }

    public function current(Request $request, ThresholdService $thresholds): AnonymousResourceCollection
    {
        return ThresholdResource::collection(
            $thresholds->latestPerMetric($request->user()->athleteOrFail())->values()
        );
    }

    /**
     * Each threshold's age, whether it is due a retest, and how to test it.
     */
    public function status(Request $request, ThresholdService $thresholds): JsonResponse
    {
        return response()->json(['data' => $thresholds->status($request->user()->athleteOrFail())]);
    }

    public function store(StoreThresholdRequest $request): ThresholdResource
    {
        $metric = $request->metric();

        $athlete = $request->user()->athleteOrFail();
        $threshold = $athlete->thresholds()->create([
            'sport' => $metric->sport(),
            'metric' => $metric,
            'value' => $request->float('value'),
            'tested_at' => $request->date('tested_at') ?? $athlete->today(),
            'source' => $request->enum('source', ThresholdSource::class) ?? ThresholdSource::Test,
        ]);

        return new ThresholdResource($threshold);
    }

    public function destroy(Threshold $threshold): Response
    {
        Gate::authorize('delete', $threshold);

        $threshold->delete();

        return response()->noContent();
    }
}
