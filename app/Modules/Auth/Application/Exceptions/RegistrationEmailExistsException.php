<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Exceptions;

use RuntimeException;

final class RegistrationEmailExistsException extends RuntimeException
{
}