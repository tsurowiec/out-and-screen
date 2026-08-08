<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser's Web Push registration. A person with the app on two devices has
 * two of these; each is independently valid until its push service says
 * otherwise.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Endpoints run past the length of an indexable column, so they're matched
     * on a hash of the URL rather than the URL itself.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
