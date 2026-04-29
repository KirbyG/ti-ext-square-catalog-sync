<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Models;

use Igniter\Flame\Database\Model;

/**
 * Records sync events, warnings, and errors.
 * The admin page shows the 20 most recent entries.
 */
class SyncLog extends Model
{
    protected $table = 'kirbygo_squarecatalogsync_logs';

    public $timestamps = false;

    protected $fillable = [
        'level',
        'message',
        'context',
        'logged_at',
    ];

    protected $casts = [
        'context' => 'array',
        'logged_at' => 'datetime',
    ];

    // ------------------------------------------------------------------
    // Levels
    // ------------------------------------------------------------------

    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    // ------------------------------------------------------------------
    // Factory helpers
    // ------------------------------------------------------------------

    public static function info(string $message, array $context = []): void
    {
        static::record(self::LEVEL_INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::record(self::LEVEL_WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::record(self::LEVEL_ERROR, $message, $context);
    }

    public static function record(string $level, string $message, array $context = []): void
    {
        static::create([
            'level' => $level,
            'message' => $message,
            'context' => $context ?: null,
            'logged_at' => now(),
        ]);

        // Keep only the last 200 entries to prevent unbounded growth.
        static::pruneOld(200);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeRecent($query, int $limit = 20)
    {
        return $query->orderByDesc('logged_at')->limit($limit);
    }

    public function scopeErrors($query)
    {
        return $query->where('level', self::LEVEL_ERROR);
    }

    // ------------------------------------------------------------------
    // Housekeeping
    // ------------------------------------------------------------------

    public static function pruneOld(int $keepCount): void
    {
        $cutoffId = static::orderByDesc('id')->skip($keepCount)->value('id');

        if ($cutoffId) {
            static::where('id', '<=', $cutoffId)->delete();
        }
    }
}
