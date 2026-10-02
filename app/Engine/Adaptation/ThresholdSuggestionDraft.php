<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\ThresholdMetric;

final readonly class ThresholdSuggestionDraft
{
    public function __construct(
        public ThresholdMetric $metric,
        public ?float $currentValue,
        public float $suggestedValue,
        public string $rationale,
    ) {}
}
