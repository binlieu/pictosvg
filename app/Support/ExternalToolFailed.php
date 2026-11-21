<?php

namespace App\Support;

use RuntimeException;

/** Internal (never rendered to clients): an external tool exited with an error. */
class ExternalToolFailed extends RuntimeException
{
    public function __construct(public readonly string $tool, public readonly string $stderr)
    {
        parent::__construct("{$tool} failed");
    }
}
