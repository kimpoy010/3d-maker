<?php

namespace App\Models;

use App\Enums\ClickerStatus;
use Database\Factories\ClickerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clicker extends Model
{
    /** @use HasFactory<ClickerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'clicker_body_id', 'name', 'artwork_path', 'settings', 'thumbnail_path', 'status', 'build_error',
        'threemf_path', 'stl_zip_path', 'downloads_unlocked_at', 'unlock_attempts', 'build_started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClickerStatus::class,
            'settings' => 'array',
            'unlock_attempts' => 'integer',
            'downloads_unlocked_at' => 'datetime',
            'build_started_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(ClickerBody::class, 'clicker_body_id');
    }

    public function isUnlocked(): bool
    {
        return $this->downloads_unlocked_at !== null;
    }

    /** Folder on the private disk that holds every file of this design. */
    public function directory(): string
    {
        return "clickers/{$this->id}";
    }
}
