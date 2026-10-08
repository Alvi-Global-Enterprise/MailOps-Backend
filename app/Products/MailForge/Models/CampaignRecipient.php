<?php

declare(strict_types=1);

namespace App\Products\MailForge\Models;

use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $table = 'campaign_recipients';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CampaignRecipientFactory
    {
        return CampaignRecipientFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'email',
        'subject',
        'personalized_body',
        'status',
        'queued_at',
        'processing_started_at',
        'sent_at',
        'failed_at',
        'error_message',
        'attempts',
        'provider_message_id',
        'custom_attributes',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'attempts' => 0,
        'custom_attributes' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecipientDeliveryStatus::class,
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
            'custom_attributes' => 'array',
        ];
    }

    /**
     * The parent campaign.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The targeted contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Delivery attempt audit logs for this recipient.
     */
    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(EmailDeliveryLog::class);
    }
}
