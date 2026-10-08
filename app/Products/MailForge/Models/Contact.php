<?php

declare(strict_types=1);

namespace App\Products\MailForge\Models;

use App\Models\User;
use App\Products\MailForge\Enums\ContactSourceType;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contacts';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'external_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'company',
        'job_title',
        'phone',
        'custom_data',
        'source',
        'source_reference',
        'is_active',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'custom_data' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'custom_data' => 'array',
            'source' => ContactSourceType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            // Normalize email to lowercase
            $contact->email = strtolower(trim($contact->email));

            // Auto-populate full_name if not explicitly set
            if (empty($contact->full_name)) {
                $names = array_filter([$contact->first_name, $contact->last_name]);
                if (! empty($names)) {
                    $contact->full_name = implode(' ', $names);
                }
            }
        });
    }

    /**
     * The user who owns this contact.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The campaign recipients targeting this contact.
     */
    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * The campaigns this contact has been assigned to.
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_recipients')
            ->withPivot(['id', 'subject', 'personalized_body', 'status', 'queued_at', 'sent_at', 'failed_at'])
            ->withTimestamps();
    }
}
