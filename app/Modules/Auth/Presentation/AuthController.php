<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation;

use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

final class AuthController extends Controller
{
    public function indexAction(): Response
    {
        return $this->jsonResponder->success([
            'status' => 'ok',
            'service' => 'At home praising God',
        ]);
    }

    public function loginAction(): Response
    {
        // Implement login here.
        return $this->jsonResponder->success([
            'message' => 'Login endpoint reached',
        ]);
    }

    public function registerAction(): Response
    {
        // Implement registration here.
        return $this->jsonResponder->success([
            'message' => 'Registration endpoint reached',
        ]);
    }
}
