<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpdateLeadRequest;
use App\Models\Lead;
use App\Models\Valuation;
use App\Services\AuditLogger;
use App\Support\CurrentClient;
use App\Support\DashboardListDefaults;
use App\Support\DashboardRange;
use App\Support\LeadPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $user */
        $user = $request->user();

        $perPage = DashboardListDefaults::perPage($request);

        $leadsQuery = Lead::query()
            ->where('client_id', $currentClient->id());

        if ($request->filled('status')) {
            $statuses = $this->normalizedStatusFilter((string) $request->query('status'));
            $leadsQuery->whereIn('status', $statuses);
        }

        if ($request->filled('range')) {
            $this->applyRangeFilter(
                $leadsQuery,
                (string) $request->query('range')
            );
        }

        if ($request->filled('q')) {
            $this->applySearchFilter(
                $leadsQuery,
                (string) $request->query('q')
            );
        }

        // Unsupported sort params are intentionally ignored until explicitly supported.
        DashboardListDefaults::applyDefaultSort($leadsQuery, 'leads');

        $leads = $leadsQuery
            ->paginate($perPage)
            ->through(fn (Lead $lead) => LeadPresenter::listItem($lead, $user));

        return response()->json(DashboardListDefaults::withDefaultSortMeta($leads, 'leads'));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $user */
        $user = $request->user();

        $lead = Lead::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $valuations = Valuation::query()
            ->where('client_id', $currentClient->id())
            ->where('conversation_id', $lead->conversation_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Valuation $valuation) => [
                'id' => $valuation->id,
                'status' => $valuation->status->value,
                'created_at' => $valuation->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $valuation->updated_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values();

        return response()->json([
            'lead_id' => $lead->id,
            'conversation_id' => $lead->conversation_id,
            'valuations' => $valuations,
            'lead' => LeadPresenter::detail($lead, $user),
        ]);
    }

    public function update(UpdateLeadRequest $request, string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $user */
        $user = $request->user();

        $lead = Lead::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $before = [
            'status' => $lead->status,
            'notes' => $lead->notes,
        ];

        $validated = $request->validated();
        $allowlist = ['status', 'notes'];
        $filtered = array_intersect_key($validated, array_flip($allowlist));

        foreach ($filtered as $key => $value) {
            $lead->{$key} = $value;
        }
        $lead->updated_by = $user->id;
        $lead->save();

        $this->auditLogger->log($user, 'lead.updated', $currentClient->id(), [
            'lead_id' => $lead->id,
            'before' => $before,
            'after' => [
                'status' => $lead->status,
                'notes' => $lead->notes,
            ],
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json(['data' => LeadPresenter::detail($lead, $user)]);
    }

    public function export(Request $request)
    {
        $this->authorize('export', Lead::class);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $user */
        $user = $request->user();

        $rows = Lead::query()
            ->where('client_id', $currentClient->id())
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'email', 'phone_normalized', 'status', 'created_at']);

        $csv = "id,name,email,phone_normalized,status,created_at\n";
        foreach ($rows as $row) {
            [$id, $name, $email, $phone, $status, $createdAt] = LeadPresenter::exportRow($row, $user);
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                $id,
                str_replace(',', ' ', (string) $name),
                str_replace(',', ' ', (string) $email),
                str_replace(',', ' ', (string) $phone),
                $status,
                $createdAt
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="leads.csv"',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedStatusFilter(string $raw): array
    {
        $status = strtoupper(trim($raw));

        return [$status];
    }

    private function applyRangeFilter($query, string $range): void
    {
        try {
            $parsed = DashboardRange::parse($range);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'range' => ['The selected range is invalid.'],
            ]);
        }

        if ($parsed->from !== null) {
            $query->whereBetween('created_at', [$parsed->from, $parsed->to]);
        }
    }

    private function applySearchFilter($query, string $rawSearch): void
    {
        $search = trim($rawSearch);
        if ($search === '') {
            return;
        }

        $needle = '%' . mb_strtolower($search) . '%';

        $query->where(function ($q) use ($needle): void {
            $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(status) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', [$needle]);
        });
    }
}
