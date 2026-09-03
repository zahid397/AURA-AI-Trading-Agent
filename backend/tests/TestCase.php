<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel Sanctum's EnsureFrontendRequestsAreStateful middleware
        // only starts a session (and therefore only authenticates via the
        // 'web' session guard) for requests that look like they came from
        // the SPA — matched by comparing the Origin/Referer header against
        // config('sanctum.stateful'). Without this, calling $request->
        // session() in a test blows up with "Session store not set on
        // request." Set a Referer matching the configured Vite dev origin
        // so feature tests exercise the same session-based auth path the
        // real frontend uses.
        $this->withHeader('Referer', 'http://localhost:5173');
    }
}
