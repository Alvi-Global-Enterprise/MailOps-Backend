<?php

declare(strict_types=1);

namespace App\Products\MailForge\Policies;

use App\Models\User;
use App\Products\MailForge\Models\CampaignRecipient;

class CampaignRecipientPolicy
{
    /**
     * Determine whether the user can view any recipients.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the campaign recipient.
     */
    public function view(User $user, CampaignRecipient $recipient): bool
    {
        return (int) $recipient->campaign?->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create campaign recipients.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the campaign recipient.
     */
    public function update(User $user, CampaignRecipient $recipient): bool
    {
        return (int) $recipient->campaign?->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the campaign recipient.
     */
    public function delete(User $user, CampaignRecipient $recipient): bool
    {
        return (int) $recipient->campaign?->user_id === (int) $user->id;
    }
}
