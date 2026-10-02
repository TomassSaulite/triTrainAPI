<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThresholdResource;
use App\Http\Resources\ThresholdSuggestionResource;
use App\Models\ThresholdSuggestion;
use App\Services\ThresholdSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ThresholdSuggestionController extends Controller
{
    public function __construct(
        private readonly ThresholdSuggestionService $suggestions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(SuggestionStatus::class)]]);

        $suggestions = $request->user()->athleteOrFail()->thresholdSuggestions()
            ->where('status', $request->query('status', SuggestionStatus::Pending->value))
            ->latest('id')
            ->get();

        return ThresholdSuggestionResource::collection($suggestions);
    }

    /**
     * Records the suggested value as a new threshold. Every future workout
     * resolves against it automatically.
     */
    public function accept(ThresholdSuggestion $thresholdSuggestion): ThresholdResource
    {
        Gate::authorize('update', $thresholdSuggestion);

        return new ThresholdResource($this->suggestions->accept($thresholdSuggestion));
    }

    public function dismiss(ThresholdSuggestion $thresholdSuggestion): ThresholdSuggestionResource
    {
        Gate::authorize('update', $thresholdSuggestion);

        $this->suggestions->dismiss($thresholdSuggestion);

        return new ThresholdSuggestionResource($thresholdSuggestion);
    }
}
