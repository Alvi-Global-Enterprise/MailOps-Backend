<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Contact\ImportContactsRequest;
use App\Http\Requests\Api\V1\Contact\StoreContactRequest;
use App\Http\Requests\Api\V1\Contact\UpdateContactRequest;
use App\Http\Resources\Api\V1\ContactResource;
use App\Products\MailForge\Actions\Contact\ImportContactsFromExcelAction;
use App\Products\MailForge\Actions\Contact\UpsertContactAction;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Display a paginated listing of the authenticated user's contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = Contact::where('user_id', $request->user()->id);

        // Optional search across email, first_name, last_name, full_name, company
        if ($search = $request->query('search')) {
            $term = '%'.trim((string) $search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('full_name', 'like', $term)
                    ->orWhere('company', 'like', $term);
            });
        }

        // Optional source filtering
        if ($source = $request->query('source', $request->query('source_type'))) {
            $query->where('source', $source);
        }

        // Optional active status filtering
        if ($request->has('is_active')) {
            $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // Whitelisted sorting
        $allowedSorts = ['id', 'first_name', 'last_name', 'email', 'created_at', 'updated_at'];
        $sortBy = in_array($request->query('sort_by'), $allowedSorts, true)
            ? (string) $request->query('sort_by')
            : 'created_at';
        $sortDir = strtolower((string) $request->query('sort_dir')) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Contacts retrieved successfully.',
            'data' => ContactResource::collection($paginator->items()),
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
     * Store a newly created contact.
     */
    public function store(StoreContactRequest $request, UpsertContactAction $upsertContactAction): JsonResponse
    {
        $this->authorize('create', Contact::class);

        $outcome = $upsertContactAction->execute(
            userId: (int) $request->user()->id,
            data: $request->validated(),
            source: ContactSourceType::MANUAL
        );

        return response()->json([
            'success' => true,
            'message' => 'Contact created successfully.',
            'data' => new ContactResource($outcome['contact']),
        ], 201);
    }

    /**
     * Display the specified contact.
     */
    public function show(Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        return response()->json([
            'success' => true,
            'message' => 'Contact retrieved successfully.',
            'data' => new ContactResource($contact),
        ]);
    }

    /**
     * Update the specified contact.
     */
    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        $validated = $request->validated();

        if (isset($validated['custom_data'])) {
            $existing = is_array($contact->custom_data) ? $contact->custom_data : [];
            $validated['custom_data'] = array_merge($existing, $validated['custom_data']);
        }

        $contact->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contact updated successfully.',
            'data' => new ContactResource($contact->fresh()),
        ]);
    }

    /**
     * Remove the specified contact.
     */
    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully.',
        ]);
    }

    /**
     * Import contacts from an uploaded Excel or CSV spreadsheet.
     */
    public function import(
        ImportContactsRequest $request,
        ImportContactsFromExcelAction $importAction
    ): JsonResponse {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $this->authorize('create', Contact::class);

        $summary = $importAction->execute(
            user: $request->user(),
            file: $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Contacts imported successfully.',
            'summary' => $summary->toArray(),
            'invalid_rows' => $summary->invalidRows,
        ], 200);
    }
}
