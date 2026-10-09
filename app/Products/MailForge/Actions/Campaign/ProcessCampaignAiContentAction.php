<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\DTOs\AiPromptPayload;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Models\AiGenerationLog;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Services\AI\AIEmailManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCampaignAiContentAction
{
    public function __construct(
        protected AIEmailManager $aiManager
    ) {}

    /**
     * Generate or polish campaign copy using the configured AI provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function execute(Campaign $campaign, array $options = []): Campaign
    {
        if ($campaign->email_mode === EmailMode::DIRECT) {
            return $campaign;
        }

        $providerName = $campaign->ai_provider?->value
            ?: (string) config('mailforge.ai.default_provider', 'openai');

        $promptText = (string) ($campaign->ai_prompt ?: '');
        $roughDraft = (string) ($campaign->raw_input_email ?: $campaign->email_body ?: '');

        $payload = new AiPromptPayload(
            prompt: $promptText,
            roughDraft: $roughDraft,
            tone: (string) ($options['tone'] ?? 'professional'),
            targetAudience: isset($options['target_audience']) ? (string) $options['target_audience'] : null,
            keyPoints: isset($options['key_points']) ? (string) $options['key_points'] : null,
            options: $options
        );

        $startTime = microtime(true);

        try {
            if ($campaign->email_mode === EmailMode::GENERATE) {
                $generated = $this->aiManager->generateEmail($payload, $providerName);
            } else { // EmailMode::POLISH
                $generated = $this->aiManager->polishEmail($payload, $providerName);
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            // Audit log
            AiGenerationLog::create([
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
                'provider' => $generated->provider,
                'model' => $generated->model,
                'operation' => $campaign->email_mode->value,
                'prompt_tokens' => $generated->promptTokens,
                'completion_tokens' => $generated->completionTokens,
                'total_tokens' => $generated->totalTokens,
                'duration_ms' => $durationMs,
                'status' => 'success',
                'error_message' => null,
                'raw_prompt' => $promptText ?: $roughDraft,
                'raw_response' => json_encode($generated->rawResponse),
            ]);

            // Transition to review status for safety
            $campaign->update([
                'subject' => $generated->subject,
                'email_body' => $generated->bodyHtml,
                'email_body_text' => $generated->bodyText,
                'status' => CampaignStatus::REVIEW,
            ]);

            // Sync generated copy to any existing recipients already attached to this campaign
            CampaignRecipient::where('campaign_id', $campaign->id)->update([
                'subject' => $generated->subject,
                'personalized_body' => $generated->bodyHtml,
                'updated_at' => now(),
            ]);

            Log::info('Campaign AI copywriting generated successfully', [
                'campaign_id' => $campaign->id,
                'mode' => $campaign->email_mode->value,
                'provider' => $generated->provider,
                'status' => CampaignStatus::REVIEW->value,
            ]);

            return $campaign->fresh();
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            AiGenerationLog::create([
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
                'provider' => $providerName,
                'model' => 'unknown',
                'operation' => $campaign->email_mode->value,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'duration_ms' => $durationMs,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'raw_prompt' => $promptText ?: $roughDraft,
                'raw_response' => null,
            ]);

            Log::error('Campaign AI copywriting generation failed', [
                'campaign_id' => $campaign->id,
                'provider' => $providerName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
