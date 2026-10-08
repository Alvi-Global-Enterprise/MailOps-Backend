<?php

declare(strict_types=1);

namespace App\Products\MailForge\Providers;

use App\Products\MailForge\Commands\MailForgeIntegrationStatusCommand;
use App\Products\MailForge\Contracts\AIEmailProviderInterface;
use App\Products\MailForge\Contracts\ContactSpreadsheetImportServiceInterface;
use App\Products\MailForge\Contracts\EmailDeliveryServiceInterface;
use App\Products\MailForge\Services\AI\AIEmailManager;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use App\Products\MailForge\Services\Import\ExcelContactImportService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class MailForgeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Contact Spreadsheet Import Service
        $this->app->singleton(ContactSpreadsheetImportServiceInterface::class, function () {
            return new ExcelContactImportService;
        });

        // AI Copywriting Manager & Default Provider
        $this->app->singleton(AIEmailManager::class, function (Application $app) {
            return new AIEmailManager($app);
        });

        $this->app->bind(AIEmailProviderInterface::class, function (Application $app) {
            return $app->make(AIEmailManager::class)->driver();
        });

        // Email Delivery Manager & Default Provider
        $this->app->singleton(EmailDeliveryManager::class, function (Application $app) {
            return new EmailDeliveryManager($app);
        });

        $this->app->bind(EmailDeliveryServiceInterface::class, function (Application $app) {
            return $app->make(EmailDeliveryManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MailForgeIntegrationStatusCommand::class,
            ]);
        }
    }
}
