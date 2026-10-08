<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaign\AddCampaignRecipientsRequest;
use App\Http\Requests\Api\V1\Campaign\GenerateCampaignRequest;
use App\Http\Requests\Api\V1\Campaign\StoreCampaignRequest;
use App\Http\Requests\Api\V1\Campaign\UpdateCampaignRequest;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Products\MailForge\Actions\Campaign\ApproveCampaignAction;
use App\Products\MailForge\Actions\Campaign\CancelCampaignAction;
use App\Products\MailForge\Actions\Campaign\CreateCampaignAction;
use App\Products\MailForge\Actions\Campaign\CreateCampaignRecipientsAction;
use App\Products\MailForge\Actions\Campaign\DispatchCampaignSendingAction;
use App\Products\MailForge\Actions\Campaign\PauseCampaignAction;
use App\Products\MailForge\Actions\Campaign\ResumeCampaignAction;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Jobs\GenerateCampaignEmailJob;
use App\Products\MailForge\Models\AiGenerationLog;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CampaignController extends Controller
{
    /**
     * Display a paginated listing of the authenticated user's campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Campaign::class);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = Campaign::where('user_id', $request->user()->id);

        // Optional search across name and subject
        if ($search = $request->query('search')) {
            $term = '%'.trim((string) $search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('subject', 'like', $term);
            });
        }

        // Optional status filter
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Optional email_mode filter
        if ($emailMode = $request->query('email_mode')) {
            $query->where('email_mode', $emailMode);
        }

        // Whitelisted sorting
        $allowedSorts = [
            'id',
            'name',
            'status',
            'email_mode',
            'created_at',
            'updated_at',
            'scheduled_at',
            'started_at',
            'completed_at',
        ];

        $sortParam = $request->query('sort', $request->query('sort_by', 'created_at'));
        $sortBy = in_array($sortParam, $allowedSorts, true) ? (string) $sortParam : 'created_at';

        $dirParam = $request->query('direction', $request->query('sort_dir', 'desc'));
        $sortDir = strtolower((string) $dirParam) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Campaigns retrieved successfully.',
            'data' => CampaignResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Store a newly created campaign.
     */
    public function store(StoreCampaignRequest $request, CreateCampaignAction $createCampaignAction): JsonResponse
    {
        $this->authorize('create', Campaign::class);

        $campaign = $createCampaignAction->execute(
            userId: (int) $request->user()->id,
            data: $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully.',
            'data' => new CampaignResource($campaign),
        ], 201);
    }

    /**
     * Display the specified campaign.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign retrieved successfully.',
            'data' => new CampaignResource($campaign),
        ]);
    }

    /**
     * Update the specified campaign.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        if (! $campaign->status->canEdit()) {
            return response()->json([
                'success' => false,
                'message' => "Campaign cannot be updated in current status [{$campaign->status->value}].",
            ], 409);
        }

        $validated = $request->validated();
        $safeAttributes = [
            'name',
            'subject',
            'email_body',
            'email_body_text',
            'ai_prompt',
            'raw_input_email',
            'ai_provider',
            'source_type',
            'source_reference',
            'scheduled_at',
        ];

        $campaign->update(array_intersect_key($validated, array_flip($safeAttributes)));

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'data' => new CampaignResource($campaign->fresh()),
        ]);
    }

    /**
     * Remove the specified campaign.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        $this->authorize('delete', $campaign);

        if (in_array($campaign->status, [CampaignStatus::SENDING, CampaignStatus::QUEUED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete campaign that is actively sending or queued. Please pause or cancel first.',
            ], 409);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully.',
        ]);
    }

    /**
     * Display a comprehensive live status report for the campaign.
     */
    public function status(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $statusCounts = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $totalRecipients = (int) ($campaign->total_recipients ?: CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $sentCount = (int) ($statusCounts[RecipientDeliveryStatus::SENT->value] ?? 0);
        $failedCount = (int) ($statusCounts[RecipientDeliveryStatus::FAILED->value] ?? 0);
        $cancelledCount = (int) ($statusCounts[RecipientDeliveryStatus::CANCELLED->value] ?? 0);

        $processedCount = $sentCount + $failedCount + $cancelledCount;
        $deliveryProgress = $totalRecipients > 0
            ? round(($processedCount / $totalRecipients) * 100, 2)
            : 0.0;

        /** @var AiGenerationLog|null $latestAiLog */
        $latestAiLog = AiGenerationLog::where('campaign_id', $campaign->id)->latest()->first();

        $generationStatus = match ($campaign->status) {
            CampaignStatus::GENERATING => 'generating',
            CampaignStatus::REVIEW => 'completed',
            default => $latestAiLog?->status ?? 'none',
        };

        return response()->json([
            'success' => true,
            'message' => 'Campaign status retrieved successfully.',
            'data' => [
                'campaign_id' => $campaign->id,
                'campaign_status' => $campaign->status->value,
                'email_mode' => $campaign->email_mode->value,
                'total_recipients' => $totalRecipients,
                'queued_count' => (int) ($statusCounts[RecipientDeliveryStatus::QUEUED->value] ?? 0),
                'processing_count' => (int) ($statusCounts[RecipientDeliveryStatus::PROCESSING->value] ?? 0),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'cancelled_count' => $cancelledCount,
                'generation_status' => $generationStatus,
                'generation_error' => $latestAiLog && $latestAiLog->status === 'error' ? $latestAiLog->error_message : null,
                'delivery_progress' => $deliveryProgress,
                'timestamps' => [
                    'created_at' => $campaign->created_at?->toISOString(),
                    'updated_at' => $campaign->updated_at?->toISOString(),
                    'scheduled_at' => $campaign->scheduled_at?->toISOString(),
                    'approved_at' => $campaign->approved_at?->toISOString(),
                    'started_at' => $campaign->started_at?->toISOString(),
                    'completed_at' => $campaign->completed_at?->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Display a paginated listing of campaign recipients.
     */
    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = CampaignRecipient::where('campaign_id', $campaign->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $term = '%'.trim((string) $search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('subject', 'like', $term);
            });
        }

        $allowedSorts = ['id', 'email', 'status', 'attempts', 'sent_at', 'failed_at', 'created_at'];
        $sortParam = $request->query('sort', $request->query('sort_by', 'created_at'));
        $sortBy = in_array($sortParam, $allowedSorts, true) ? (string) $sortParam : 'created_at';

        $dirParam = $request->query('direction', $request->query('sort_dir', 'desc'));
        $sortDir = strtolower((string) $dirParam) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Campaign recipients retrieved successfully.',
            'data' => CampaignRecipientResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Attach contacts as recipients to the campaign.
     */
    public function addRecipients(
        AddCampaignRecipientsRequest $request,
        Campaign $campaign,
        CreateCampaignRecipientsAction $createRecipientsAction
    ): JsonResponse {
        $this->authorize('manageRecipients', $campaign);

        if (! in_array($campaign->status, [CampaignStatus::DRAFT, CampaignStatus::REVIEW, CampaignStatus::APPROVED], true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot add recipients to campaign in [{$campaign->status->value}] status.",
            ], 409);
        }

        $contactIds = array_map('intval', (array) $request->validated('contact_ids'));

        // Reject cross-user contacts: ensure all target contacts belong to current user
        $userContactCount = Contact::where('user_id', $request->user()->id)
            ->whereIn('id', $contactIds)
            ->count();

        if ($userContactCount !== count(array_unique($contactIds))) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: One or more contacts do not belong to you.',
            ], 403);
        }

        $totalRecipients = $createRecipientsAction->execute($campaign, $contactIds);

        return response()->json([
            'success' => true,
            'message' => 'Recipients attached to campaign successfully.',
            'data' => [
                'campaign_id' => $campaign->id,
                'total_recipients' => $totalRecipients,
            ],
        ]);
    }

    /**
     * Trigger background AI copywriting generation or polishing for the campaign.
     */
    public function generate(GenerateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('generate', $campaign);

        if ($campaign->email_mode === EmailMode::DIRECT) {
            return response()->json([
                'success' => false,
                'message' => 'Direct email campaigns do not support AI generation.',
            ], 422);
        }

        if ($campaign->status === CampaignStatus::GENERATING) {
            return response()->json([
                'success' => false,
                'message' => 'AI generation is already in progress for this campaign.',
            ], 409);
        }

        if ($campaign->status->isTerminal() || in_array($campaign->status, [CampaignStatus::SENDING, CampaignStatus::QUEUED], true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot trigger AI generation for campaign in [{$campaign->status->value}] status.",
            ], 409);
        }

        $validated = $request->validated();

        if (! empty($validated['ai_prompt'])) {
            $campaign->update(['ai_prompt' => trim((string) $validated['ai_prompt'])]);
        }

        // Atomically set to generating state to guarantee API idempotency
        $campaign->update(['status' => CampaignStatus::GENERATING]);

        $options = array_filter([
            'tone' => $validated['tone'] ?? null,
            'target_audience' => $validated['target_audience'] ?? null,
            'key_points' => $validated['key_points'] ?? null,
        ]);

        // Dispatch background queue job — NEVER automatically sends email!
        GenerateCampaignEmailJob::dispatch($campaign->id, $options);

        return response()->json([
            'success' => true,
            'message' => 'Campaign AI generation queued.',
            'data' => [
                'campaign_id' => $campaign->id,
                'status' => CampaignStatus::GENERATING->value,
            ],
        ], 202);
    }

    /**
     * Approve the campaign for sending.
     */
    public function approve(Campaign $campaign, ApproveCampaignAction $approveAction): JsonResponse
    {
        $this->authorize('approve', $campaign);

        try {
            $approvedCampaign = $approveAction->execute($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign approved successfully.',
                'data' => new CampaignResource($approvedCampaign),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Dispatch an approved campaign for queue delivery.
     */
    public function dispatch(Campaign $campaign, DispatchCampaignSendingAction $dispatchAction): JsonResponse
    {
        $this->authorize('dispatch', $campaign);

        if ($campaign->status !== CampaignStatus::APPROVED) {
            return response()->json([
                'success' => false,
                'message' => "Only approved campaigns can be dispatched. Current status: [{$campaign->status->value}].",
            ], 409);
        }

        try {
            $dispatchedCount = $dispatchAction->execute($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign dispatch initiated successfully.',
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => CampaignStatus::SENDING->value,
                    'enqueued_recipients' => $dispatchedCount,
                ],
            ], 202);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Pause an in-flight campaign.
     */
    public function pause(Campaign $campaign, PauseCampaignAction $pauseAction): JsonResponse
    {
        $this->authorize('pause', $campaign);

        try {
            $pausedCampaign = $pauseAction->execute($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign paused successfully.',
                'data' => new CampaignResource($pausedCampaign),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Resume a paused campaign.
     */
    public function resume(Campaign $campaign, ResumeCampaignAction $resumeAction): JsonResponse
    {
        $this->authorize('resume', $campaign);

        try {
            $resumedCampaign = $resumeAction->execute($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign resumed successfully.',
                'data' => new CampaignResource($resumedCampaign),
            ], 202);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Cancel an active or pending campaign.
     */
    public function cancel(Campaign $campaign, CancelCampaignAction $cancelAction): JsonResponse
    {
        $this->authorize('cancel', $campaign);

        try {
            $cancelledCampaign = $cancelAction->execute($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign cancelled successfully.',
                'data' => new CampaignResource($cancelledCampaign),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Display aggregate recipient delivery information.
     */
    public function deliveryStatus(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $statusCounts = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Campaign delivery status retrieved successfully.',
            'data' => [
                'campaign_id' => $campaign->id,
                'total' => CampaignRecipient::where('campaign_id', $campaign->id)->count(),
                RecipientDeliveryStatus::DRAFT->value => (int) ($statusCounts[RecipientDeliveryStatus::DRAFT->value] ?? 0),
                RecipientDeliveryStatus::QUEUED->value => (int) ($statusCounts[RecipientDeliveryStatus::QUEUED->value] ?? 0),
                RecipientDeliveryStatus::PROCESSING->value => (int) ($statusCounts[RecipientDeliveryStatus::PROCESSING->value] ?? 0),
                RecipientDeliveryStatus::SENT->value => (int) ($statusCounts[RecipientDeliveryStatus::SENT->value] ?? 0),
                RecipientDeliveryStatus::FAILED->value => (int) ($statusCounts[RecipientDeliveryStatus::FAILED->value] ?? 0),
                RecipientDeliveryStatus::CANCELLED->value => (int) ($statusCounts[RecipientDeliveryStatus::CANCELLED->value] ?? 0),
            ],
        ]);
    }
}
