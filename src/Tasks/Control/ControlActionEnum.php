<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks\Control;

enum ControlActionEnum: string
{
    /** Stop ticking: the whole pool (which ends the process) or one named task. */
    case Stop = 'stop';

    /** Rebuild the task instance and keep ticking it, dropping whatever state it held. */
    case Restart = 'restart';
}
