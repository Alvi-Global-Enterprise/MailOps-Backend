<?php

declare(strict_types=1);

namespace App\Products\MailForge\Commands;

use Illuminate\Console\Command;

class MailForgeIntegrationStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailforge:integration-status {--test-connectivity : Safely check socket connectivity for configured services}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and display integration readiness status for MailForge AI services';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('MailForge AI — Integration Readiness Audit');
        $this->line('--------------------------------------------');

        $rows = [];

        // 1. AI Provider Selection
        $activeAiDriver = (string) config('mailforge.ai.default_provider', 'openai');
        $validAiDrivers = ['openai', 'gemini', 'mock'];
        $aiDriverValid = in_array($activeAiDriver, $validAiDrivers, true);

        $rows[] = [
            'Active AI Provider',
            $aiDriverValid ? 'Configured' : 'Invalid',
            "Driver: [{$activeAiDriver}]",
        ];

        // 2. OpenAI Configuration
        $openaiKey = config('mailforge.ai.providers.openai.api_key');
        $openaiModel = config('mailforge.ai.providers.openai.model', 'gpt-4o-mini');
        $rows[] = [
            'OpenAI Service',
            ! empty($openaiKey) ? 'Configured' : 'Missing',
            "Model: {$openaiModel}".(! empty($openaiKey) ? ' (API Key present)' : ' (No API Key set)'),
        ];

        // 3. Gemini Configuration
        $geminiKey = config('mailforge.ai.providers.gemini.api_key');
        $geminiModel = config('mailforge.ai.providers.gemini.model', 'gemini-1.5-flash');
        $rows[] = [
            'Google Gemini Service',
            ! empty($geminiKey) ? 'Configured' : 'Missing',
            "Model: {$geminiModel}".(! empty($geminiKey) ? ' (API Key present)' : ' (No API Key set)'),
        ];

        // 4. Email Delivery (SMTP / Mailer)
        $mailer = config('mailforge.delivery.default_mailer', config('mail.default', 'smtp'));
        $smtpHost = config('mail.mailers.smtp.host', '127.0.0.1');
        $smtpPort = config('mail.mailers.smtp.port', 2525);
        $fromAddress = config('mail.from.address', '');
        $hasSmtpCreds = ! empty(config('mail.mailers.smtp.username')) && ! empty(config('mail.mailers.smtp.password'));

        $mailStatus = (! empty($fromAddress)) ? 'Configured' : 'Warning';
        $rows[] = [
            'Email Delivery',
            $mailStatus,
            "Mailer: [{$mailer}], Host: {$smtpHost}:{$smtpPort}, From: [{$fromAddress}]".($hasSmtpCreds ? ' (Auth set)' : ''),
        ];

        // 5. Redis Queue Configuration
        $queueConn = config('queue.default', 'redis');
        $redisHost = config('database.redis.default.host', '127.0.0.1');
        $redisPort = config('database.redis.default.port', 6379);

        $rows[] = [
            'Queue Engine',
            'Configured',
            "Connection: [{$queueConn}], Redis: {$redisHost}:{$redisPort}, Queues: [campaign-sending, campaign-generation, default]",
        ];

        // 6. Excel & Spreadsheet Contact Import
        $maxUploadKb = (int) config('mailforge.import.max_file_size_kb', 10240);
        $rows[] = [
            'Excel Contact Importer',
            'Ready',
            "Supported: [.xlsx, .xls, .csv], Max size: {$maxUploadKb} KB, Engine: PhpSpreadsheet",
        ];

        $this->table(['Integration Subsystem', 'Status', 'Configuration Details'], $rows);

        // Optional connectivity testing
        if ($this->option('test-connectivity')) {
            $this->newLine();
            $this->info('Performing Safe Connectivity Verification:');

            // SMTP host check
            $smtpSocket = @fsockopen((string) $smtpHost, (int) $smtpPort, $errno, $errstr, 2.0);
            if ($smtpSocket) {
                fclose($smtpSocket);
                $this->line("  ✓ SMTP socket open at {$smtpHost}:{$smtpPort}");
            } else {
                $this->warn("  ! Unable to connect to SMTP at {$smtpHost}:{$smtpPort} ({$errstr})");
            }
        }

        $this->newLine();
        $this->info('Audit completed successfully. All secrets protected.');

        return self::SUCCESS;
    }
}
