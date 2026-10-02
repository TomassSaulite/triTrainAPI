<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

interface AdaptationRule
{
    /**
     * @param  list<PlanChange>  $earlier  changes made by rules that ran before this one
     * @return list<PlanChange>
     */
    public function evaluate(AdaptationContext $context, array $earlier): array;
}
