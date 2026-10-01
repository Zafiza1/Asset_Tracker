<?php

namespace App\Exceptions;

/**
 * A module lifecycle rule was violated (wrong state, unmet dependency, invalid
 * configuration).
 */
class ModuleException extends ApiException
{
}
