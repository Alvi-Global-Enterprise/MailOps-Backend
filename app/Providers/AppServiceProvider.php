<?php

namespace App\Providers;

use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use App\Products\MailForge\Policies\CampaignPolicy;
use App\Products\MailForge\Policies\CampaignRecipientPolicy;
use App\Products\MailForge\Policies\ContactPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(CampaignRecipient::class, CampaignRecipientPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
    }
}
