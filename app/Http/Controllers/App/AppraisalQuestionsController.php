<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CreateAppraisalQuestionRequest;
use App\Http\Requests\App\ReorderAppraisalQuestionsRequest;
use App\Http\Requests\App\UpdateAppraisalQuestionRequest;
use App\Models\AppraisalQuestion;
use App\Support\AppraisalQuestionPresenter;
use App\Support\CurrentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppraisalQuestionsController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $includeInactive = filter_var(request()->query('include_inactive', false), FILTER_VALIDATE_BOOL);

        $query = AppraisalQuestion::query()
            ->where('client_id', $currentClient->id())
            ->orderBy('order_index');

        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        $questions = $query->get();

        return response()->json([
            'data' => $questions->map(fn (AppraisalQuestion $question) => AppraisalQuestionPresenter::present($question))->values(),
        ]);
    }

    public function store(CreateAppraisalQuestionRequest $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $validated = $request->validated();

        $nextOrder = ((int) AppraisalQuestion::query()
            ->where('client_id', $currentClient->id())
            ->where('is_active', true)
            ->max('order_index')) + 1;

        $question = AppraisalQuestion::create([
            'client_id' => $currentClient->id(),
            'key' => (string) $validated['key'],
            'label' => (string) $validated['question'],
            'input_type' => (string) $validated['type'],
            'options' => $validated['type'] === 'select' ? array_values($validated['options'] ?? []) : null,
            'required' => (bool) ($validated['is_required'] ?? true),
            'help_text' => $validated['help_text'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'order_index' => $nextOrder > 0 ? $nextOrder : 1,
        ]);

        return response()->json([
            'data' => AppraisalQuestionPresenter::present($question),
        ], 201);
    }

    public function update(UpdateAppraisalQuestionRequest $request, string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $question = AppraisalQuestion::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validated();

        if (array_key_exists('key', $validated) && $validated['key'] !== $question->key) {
            throw ValidationException::withMessages([
                'key' => ['The key field is immutable.'],
            ]);
        }

        $targetType = (string) ($validated['type'] ?? $question->input_type);
        $hasOptions = array_key_exists('options', $validated);

        if ($targetType === 'select') {
            $changingToSelect = ($validated['type'] ?? null) === 'select' && $question->input_type !== 'select';
            if ($changingToSelect && !$hasOptions) {
                throw ValidationException::withMessages([
                    'options' => ['The options field is required when changing type to select.'],
                ]);
            }
        }

        if ($targetType !== 'select' && $hasOptions) {
            throw ValidationException::withMessages([
                'options' => ['The options field is only allowed when type is select.'],
            ]);
        }

        if (array_key_exists('question', $validated)) {
            $question->label = (string) $validated['question'];
        }

        if (array_key_exists('type', $validated)) {
            $question->input_type = $targetType;
            if ($targetType !== 'select') {
                $question->options = null;
            }
        }

        if ($targetType === 'select' && $hasOptions) {
            $question->options = is_array($validated['options']) ? array_values($validated['options']) : null;
        }

        if (array_key_exists('is_required', $validated)) {
            $question->required = (bool) $validated['is_required'];
        }

        if (array_key_exists('help_text', $validated)) {
            $question->help_text = $validated['help_text'];
        }

        if (array_key_exists('is_active', $validated)) {
            $question->is_active = (bool) $validated['is_active'];
        }

        $question->save();

        return response()->json([
            'data' => AppraisalQuestionPresenter::present($question),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $question = AppraisalQuestion::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $question->is_active = false;
        $question->save();

        return response()->json(['ok' => true]);
    }

    public function reorder(ReorderAppraisalQuestionsRequest $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        /** @var array<int, string> $orderedIds */
        $orderedIds = $request->validated('ordered_ids');

        $activeIds = AppraisalQuestion::query()
            ->where('client_id', $currentClient->id())
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        sort($activeIds);
        $inputSorted = $orderedIds;
        sort($inputSorted);

        if ($activeIds !== $inputSorted) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['ordered_ids must include exactly all active question IDs for the current client.'],
            ]);
        }

        DB::transaction(function () use ($currentClient, $orderedIds): void {
            AppraisalQuestion::query()
                ->where('client_id', $currentClient->id())
                ->where('is_active', true)
                ->lockForUpdate()
                ->get(['id']);

            foreach ($orderedIds as $index => $id) {
                AppraisalQuestion::query()
                    ->where('client_id', $currentClient->id())
                    ->where('is_active', true)
                    ->where('id', $id)
                    ->update(['order_index' => $index + 1]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
