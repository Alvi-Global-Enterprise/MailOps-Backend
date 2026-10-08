<?php

declare(strict_types=1);

namespace App\Products\MailForge\Models;

use Database\Factories\EmailDeliveryLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDeliveryLog extends Model
{
    use HasFactory;

    protected $table = 'email_delivery_logs';

    public const UPDATED_AT = null;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): EmailDeliveryLogFactory
    {
        return EmailDeliveryLogFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'campaign_recipient_id',
        'campaign_id',
        'provider',
        'status',
        'response_code',
        'provider_response',
        'attempt',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The recipient being delivered to.
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    /**
     * The campaign owning this delivery log.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
