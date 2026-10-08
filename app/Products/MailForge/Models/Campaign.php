<?php

declare(strict_types=1);

namespace App\Products\MailForge\Models;

use App\Models\User;
use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'campaigns';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'subject',
        'source_type',
        'source_reference',
        'email_mode',
        'ai_provider',
        'ai_prompt',
        'raw_input_email',
        'email_body',
        'email_body_text',
        'status',
        'scheduled_at',
        'approved_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'failed_count',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'email_mode' => 'direct',
        'total_recipients' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'email_mode' => EmailMode::class,
            'ai_provider' => AiProviderType::class,
            'scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * The user who created the campaign.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Individual recipient deliveries for this campaign.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * Contacts targeted by this campaign.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'campaign_recipients')
            ->withPivot(['id', 'subject', 'personalized_body', 'status', 'queued_at', 'sent_at', 'failed_at'])
            ->withTimestamps();
    }

    /**
     * AI generation logs generated for this campaign.
     */
    public function aiGenerationLogs(): HasMany
    {
        return $this->hasMany(AiGenerationLog::class);
    }

    /**
     * Delivery attempt audit logs for this campaign.
     */
    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(EmailDeliveryLog::class);
    }
}
