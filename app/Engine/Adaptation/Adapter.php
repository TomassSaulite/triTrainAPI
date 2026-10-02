<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Engine\Adaptation\Rules\ExtendedMissRule;
use App\Engine\Adaptation\Rules\FatigueRule;
use App\Engine\Adaptation\Rules\HowYouFeelRule;
use App\Engine\Adaptation\Rules\MissedKeySessionRule;
use App\Engine\Adaptation\Rules\OverComplianceRule;

/**
 * The adaptation loop's decision step: runs the rules in order and collects
 * the changes they ask for. A regeneration supersedes everything after it.
 */
final class Adapter
{
    /**
     * @var list<AdaptationRule>
     */
    private readonly array $rules;

    /**
     * @param  list<AdaptationRule>|null  $rules  in the order they are checked
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? [
            new MissedKeySessionRule,
            new ExtendedMissRule,
            new FatigueRule,
            new HowYouFeelRule,
            new OverComplianceRule,
        ];
    }

    /**
     * @return list<PlanChange>
     */
    public function adapt(AdaptationContext $context): array
    {
        $changes = [];

        foreach ($this->rules as $rule) {
            $new = $rule->evaluate($context, $changes);

            foreach ($new as $change) {
                if ($change->type === ChangeType::Regenerate) {
                    return [$change];
                }
            }

            array_push($changes, ...$new);
        }

        return $changes;
    }
}
