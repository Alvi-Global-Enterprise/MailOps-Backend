<?php

declare(strict_types=1);

namespace App\Products\MailForge\Models;

use App\Models\User;
use Database\Factories\AiGenerationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGenerationLog extends Model
{
    use HasFactory;

    protected $table = 'ai_generation_logs';

    public const UPDATED_AT = null;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AiGenerationLogFactory
    {
        return AiGenerationLogFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'user_id',
        'provider',
        'model',
        'operation',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'duration_ms',
        'status',
        'error_message',
        'raw_prompt',
        'raw_response',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who triggered this AI generation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The associated campaign (if applicable).
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
