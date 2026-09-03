<?php

namespace App\Models;

use App\Enums\RunMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSettings extends Model
{
    use HasUuids;

    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'mode',
        'confidence_threshold',
        'notification_prefs',
    ];

    protected function casts(): array
    {
        return [
            'mode' => RunMode::class,
            'confidence_threshold' => 'decimal:4',
            'notification_prefs' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
