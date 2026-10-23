<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use RuntimeException;

final class InvalidCredentialsException extends RuntimeException
{
}
