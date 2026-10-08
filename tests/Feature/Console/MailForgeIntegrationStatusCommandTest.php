<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MailForgeIntegrationStatusCommandTest extends TestCase
{
    public function test_integration_status_command_executes_and_displays_table_without_secrets(): void
    {
        $exitCode = Artisan::call('mailforge:integration-status');

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString('MailForge AI — Integration Readiness Audit', $output);
        $this->assertStringContainsString('Active AI Provider', $output);
        $this->assertStringContainsString('OpenAI Service', $output);
        $this->assertStringContainsString('Google Gemini Service', $output);
        $this->assertStringContainsString('Email Delivery', $output);
        $this->assertStringContainsString('Queue Engine', $output);
        $this->assertStringContainsString('Excel Contact Importer', $output);
        $this->assertStringContainsString('Audit completed successfully. All secrets protected.', $output);

        // Verify no raw API keys or passwords are leaked
        $this->assertStringNotContainsString('OPENAI_API_KEY', $output);
        $this->assertStringNotContainsString('GEMINI_API_KEY', $output);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $output);
    }
}
