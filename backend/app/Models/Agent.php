<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'enabled',
        'status',
        'config_version',
    ];

    protected function casts(): array
    {
        return [
            'type' => AgentType::class,
            'enabled' => 'boolean',
            'status' => AgentStatus::class,
            'config_version' => 'integer',
        ];
    }
}
