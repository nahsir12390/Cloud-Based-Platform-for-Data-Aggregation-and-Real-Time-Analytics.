<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustDeploymentProxies extends TrustProxies
{
    protected function proxies(): array|string|null
    {
        return config('deployment.trust_proxies') ? '*' : null;
    }

    protected function getTrustedHeaderNames(): int
    {
        return Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;
    }
}
