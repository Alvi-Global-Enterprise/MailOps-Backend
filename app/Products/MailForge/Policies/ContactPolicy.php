<?php

declare(strict_types=1);

namespace App\Products\MailForge\Policies;

use App\Models\User;
use App\Products\MailForge\Models\Contact;

class ContactPolicy
{
    /**
     * Determine whether the user can view any contacts.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the contact.
     */
    public function view(User $user, Contact $contact): bool
    {
        return (int) $contact->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create contacts.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the contact.
     */
    public function update(User $user, Contact $contact): bool
    {
        return (int) $contact->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the contact.
     */
    public function delete(User $user, Contact $contact): bool
    {
        return (int) $contact->user_id === (int) $user->id;
    }
}
