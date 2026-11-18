<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Jnbrnplbr\WorkflowPipelines\Actions\DuplicateWorkflowAction;
use Jnbrnplbr\WorkflowPipelines\Actions\SyncWorkflowStepSettingsAction;
use Jnbrnplbr\WorkflowPipelines\Http\Requests\WorkflowDuplicateRequest;
use Jnbrnplbr\WorkflowPipelines\Http\Requests\WorkflowStoreRequest;
use Jnbrnplbr\WorkflowPipelines\Http\Requests\WorkflowUpdateRequest;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class WorkflowController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Workflow::class);
        $filter = (string) $request->query('filter', 'active');
        $query = Workflow::query()->withCount(['steps', 'transactions as usage_count']);

        if ($filter === 'deleted') {
            $query->onlyTrashed();
        } elseif ($filter === 'all') {
            $query->withTrashed();
        } else {
            $filter = 'active';
        }

        $user = $request->user();

        return Inertia::render($this->page('Workflows', 'Index'), [
            'workflows' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'filter' => $filter,
            'can' => [
                'manage' => $user instanceof Model && app(WorkflowAuthorizer::class)->isSuperAdministrator($user),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Workflow::class);

        return Inertia::render($this->page('Workflows', 'Form'), [
            'workflow' => null,
            'registries' => $this->registries(),
            ...$this->stepOptions(),
        ]);
    }

    public function edit(Workflow $workflow): Response
    {
        Gate::authorize('view', $workflow);
        $workflow->load(['steps.role', 'steps.displayStatus', 'steps.rules', 'steps.notifications']);

        return Inertia::render($this->page('Workflows', 'Form'), [
            'workflow' => $workflow,
            'registries' => $this->registries(),
            ...$this->stepOptions(),
            'can' => ['manage' => Gate::allows('update', $workflow)],
        ]);
    }

    public function store(WorkflowStoreRequest $request, SyncWorkflowStepSettingsAction $sync): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $sync): void {
            $workflow = Workflow::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $sync->execute($workflow, $data['steps']);
        });

        return redirect()->route($this->routeName('workflows.index'))
            ->with('success', __('workflow-pipelines::workflows.created'));
    }

    public function update(WorkflowUpdateRequest $request, Workflow $workflow, SyncWorkflowStepSettingsAction $sync): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $workflow, $sync): void {
            $workflow->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ])->save();
            $sync->execute($workflow, $data['steps']);
        });

        return redirect()->route($this->routeName('workflows.index'))
            ->with('success', __('workflow-pipelines::workflows.updated'));
    }

    public function duplicate(
        WorkflowDuplicateRequest $request,
        Workflow $workflow,
        DuplicateWorkflowAction $duplicate,
    ): RedirectResponse {
        $copy = $duplicate->execute($workflow, $request->validated('name'));

        return redirect()->route($this->routeName('workflows.edit'), $copy)
            ->with('success', __('workflow-pipelines::workflows.duplicated'));
    }

    public function destroy(Workflow $workflow): RedirectResponse
    {
        Gate::authorize('delete', $workflow);
        $workflow->delete();

        return redirect()->route($this->routeName('workflows.index'))
            ->with('success', __('workflow-pipelines::workflows.deleted'));
    }

    public function restore(string $workflowId): RedirectResponse
    {
        $workflow = Workflow::withTrashed()->findOrFail($workflowId);
        Gate::authorize('restore', $workflow);
        $workflow->restore();

        return redirect()->route($this->routeName('workflows.index'), ['filter' => 'all'])
            ->with('success', __('workflow-pipelines::workflows.restored'));
    }

    /** @return list<array{type: string, label: string, fields: array<string, string>}> */
    private function registries(): array
    {
        $items = [];

        foreach (app(ApprovableModelRegistry::class)->all() as $modelClass) {
            $model = new $modelClass;

            if (! $model instanceof Model) {
                continue;
            }

            $items[] = [
                'type' => $model->getMorphClass(),
                'label' => $modelClass::workflowModelLabel(),
                'fields' => $modelClass::workflowEditableFields(),
            ];
        }

        return $items;
    }

    /** @return array{roles: list<array{id: int|string, name: string}>, statuses: list<array{id: int|string, name: string}>} */
    private function stepOptions(): array
    {
        $roleModel = (string) config('workflow-pipelines.models.role');
        $statusModel = (string) config('workflow-pipelines.models.status');
        $options = static function (string $model): array {
            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                return [];
            }

            return $model::query()->orderBy('name')->get()->map(static fn (Model $item): array => [
                'id' => $item->getKey(),
                'name' => (string) ($item->getAttribute('name') ?? $item->getKey()),
            ])->all();
        };

        return ['roles' => $options($roleModel), 'statuses' => $options($statusModel)];
    }

    private function page(string $section, string $page): string
    {
        return trim((string) config('workflow-pipelines.inertia.pages_path', 'Vendor/WorkflowPipelines'), '/').'/'.$section.'/'.$page;
    }

    private function routeName(string $name): string
    {
        return (string) config('workflow-pipelines.routes.name', 'development.pipelines.').$name;
    }
}
