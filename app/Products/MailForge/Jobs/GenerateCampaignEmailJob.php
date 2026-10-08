<?php

declare(strict_types=1);

namespace App\Products\MailForge\Jobs;

use App\Products\MailForge\Actions\Campaign\ProcessCampaignAiContentAction;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateCampaignEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 60];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $options = []
    ) {
        $this->onQueue('campaign-generation');
    }

    /**
     * Execute the job.
     */
    public function handle(ProcessCampaignAiContentAction $processAction): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            Log::warning('GenerateCampaignEmailJob aborted: campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        // Set to generating status
        $campaign->update(['status' => CampaignStatus::GENERATING]);

        $processAction->execute($campaign, $this->options);
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateCampaignEmailJob permanently failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception?->getMessage(),
        ]);

        $campaign = Campaign::find($this->campaignId);
        if ($campaign) {
            $campaign->update(['status' => CampaignStatus::FAILED]);
        }
    }
}
