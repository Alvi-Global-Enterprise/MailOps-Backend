<?php

declare(strict_types=1);

namespace App\Products\MailForge\Policies;

use App\Models\User;
use App\Products\MailForge\Models\Campaign;

class CampaignPolicy
{
    /**
     * Determine whether the user can view any campaigns.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the campaign.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create campaigns.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the campaign.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the campaign.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can trigger AI generation for the campaign.
     */
    public function generate(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can approve the campaign.
     */
    public function approve(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can dispatch the campaign.
     */
    public function dispatch(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can pause the campaign.
     */
    public function pause(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can resume the campaign.
     */
    public function resume(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can cancel the campaign.
     */
    public function cancel(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can manage recipients for the campaign.
     */
    public function manageRecipients(User $user, Campaign $campaign): bool
    {
        return (int) $campaign->user_id === (int) $user->id;
    }
}
