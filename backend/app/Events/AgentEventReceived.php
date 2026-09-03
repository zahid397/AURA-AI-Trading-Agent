<?php

namespace App\Events;

use App\Models\AgentEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an internal event from the ai-engine has been persisted.
 *
 * NOTE: broadcasting over Reverb/WebSocket is not wired up yet (out of
 * scope for this pass — see backend/README.md "Backend status"). The
 * frontend falls back to polling the REST endpoints in the meantime. Once
 * Reverb is added, a `ShouldBroadcast` listener/event can translate this
 * into the per-channel frontend events described in docs/architecture.md
 * without changing EventsController.
 */
class AgentEventReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AgentEvent $event)
    {
        //
    }
}
