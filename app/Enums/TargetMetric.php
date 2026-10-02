<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Workout targets are stored relative to the athlete's thresholds. Every metric
 * is expressed so that 1.0 is threshold and higher means harder: pace metrics
 * are fractions of threshold *speed*, not of threshold pace.
 */
enum TargetMetric: string
{
    case FtpPct = 'ftp_pct';
    case ThresholdPacePct = 'threshold_pace_pct';
    case CssPct = 'css_pct';
    case LthrPct = 'lthr_pct';
}
