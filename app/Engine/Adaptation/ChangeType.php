<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

enum ChangeType: string
{
    /** Add a copy of a missed session on a later day. */
    case Reschedule = 'reschedule';

    /** Take a session out of the plan. */
    case Drop = 'drop';

    /** Make a session longer or shorter by a factor. */
    case Scale = 'scale';

    /** Turn a session into an easy recovery session. */
    case Recover = 'recover';

    /** Throw away the future of the plan and generate it again. */
    case Regenerate = 'regenerate';
}
