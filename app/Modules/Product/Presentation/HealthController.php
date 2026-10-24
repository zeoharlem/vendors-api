<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

final class HealthController extends Controller
{
    public function indexAction(): Response
    {
        return $this->jsonResponder->success([
            'status' => 'ok',
            'service' => 'marketplace-api',
        ]);
    }
}
