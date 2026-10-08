<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Models\Campaign;
use InvalidArgumentException;

class CreateCampaignAction
{
    /**
     * Create a campaign record validating mode-specific inputs.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(int $userId, array $data): Campaign
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (empty($name)) {
            throw new InvalidArgumentException('Campaign name is required.');
        }

        $mode = $data['email_mode'] instanceof EmailMode
            ? $data['email_mode']
            : EmailMode::from((string) ($data['email_mode'] ?? 'direct'));

        $subject = isset($data['subject']) ? trim((string) $data['subject']) : null;
        $emailBody = isset($data['email_body']) ? trim((string) $data['email_body']) : null;
        $aiPrompt = isset($data['ai_prompt']) ? trim((string) $data['ai_prompt']) : null;
        $rawInputEmail = isset($data['raw_input_email']) ? trim((string) $data['raw_input_email']) : null;

        // Mode-specific validation
        if ($mode === EmailMode::DIRECT) {
            if (empty($subject)) {
                throw new InvalidArgumentException('Direct email mode requires a subject line.');
            }
            if (empty($emailBody)) {
                throw new InvalidArgumentException('Direct email mode requires an email body.');
            }
        } elseif ($mode === EmailMode::GENERATE) {
            if (empty($aiPrompt)) {
                throw new InvalidArgumentException('Generate mode requires an AI prompt instruction.');
            }
        } elseif ($mode === EmailMode::POLISH) {
            if (empty($rawInputEmail) && empty($emailBody)) {
                throw new InvalidArgumentException('Polish mode requires rough draft email content to polish.');
            }
            if (empty($rawInputEmail)) {
                $rawInputEmail = $emailBody;
            }
        }

        $aiProvider = null;
        if (! empty($data['ai_provider'])) {
            $aiProvider = $data['ai_provider'] instanceof AiProviderType
                ? $data['ai_provider']
                : AiProviderType::from((string) $data['ai_provider']);
        } elseif ($mode->requiresAi()) {
            $aiProvider = AiProviderType::from((string) config('mailforge.ai.default_provider', 'openai'));
        }

        return Campaign::create([
            'user_id' => $userId,
            'name' => $name,
            'subject' => $subject,
            'source_type' => (string) ($data['source_type'] ?? 'all_contacts'),
            'source_reference' => isset($data['source_reference']) ? (string) $data['source_reference'] : null,
            'email_mode' => $mode,
            'ai_provider' => $aiProvider,
            'ai_prompt' => $aiPrompt,
            'raw_input_email' => $rawInputEmail,
            'email_body' => $emailBody,
            'email_body_text' => isset($data['email_body_text']) ? (string) $data['email_body_text'] : null,
            'status' => CampaignStatus::DRAFT,
            'scheduled_at' => isset($data['scheduled_at']) ? $data['scheduled_at'] : null,
            'total_recipients' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ]);
    }
}
