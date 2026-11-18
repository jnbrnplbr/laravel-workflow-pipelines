<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Jnbrnplbr\WorkflowPipelines\Http\Requests\WorkflowPipelineRequest;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class WorkflowPipelineController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkflowPipeline::class);
        $filter = (string) $request->query('filter', 'active');
        $query = WorkflowPipeline::query()->with('workflow');

        if ($filter === 'deleted') {
            $query->onlyTrashed();
        } elseif ($filter === 'all') {
            $query->withTrashed();
        } else {
            $filter = 'active';
        }

        $user = $request->user();

        return Inertia::render($this->page('Pipelines', 'Index'), [
            'pipelines' => $query->orderBy('model_type')->orderBy('unit_id')->paginate(20)->withQueryString(),
            'filter' => $filter,
            'transactionTypes' => $this->transactionTypes(),
            'workflows' => Workflow::query()->orderBy('name')->get(['id', 'name']),
            'organizations' => $this->organizations(),
            'can' => [
                'manage' => $user instanceof Model && app(WorkflowAuthorizer::class)->isSuperAdministrator($user),
            ],
        ]);
    }

    public function store(WorkflowPipelineRequest $request): RedirectResponse
    {
        WorkflowPipeline::query()->create($request->validated());

        return redirect()->route($this->routeName('workflow-pipelines.index'))
            ->with('success', __('workflow-pipelines::pipelines.created'));
    }

    public function update(WorkflowPipelineRequest $request, WorkflowPipeline $pipeline): RedirectResponse
    {
        $pipeline->fill($request->validated())->save();

        return redirect()->route($this->routeName('workflow-pipelines.index'))
            ->with('success', __('workflow-pipelines::pipelines.updated'));
    }

    public function destroy(WorkflowPipeline $pipeline): RedirectResponse
    {
        Gate::authorize('delete', $pipeline);
        $pipeline->delete();

        return redirect()->route($this->routeName('workflow-pipelines.index'))
            ->with('success', __('workflow-pipelines::pipelines.deleted'));
    }

    public function restore(string $pipelineId): RedirectResponse
    {
        $pipeline = WorkflowPipeline::withTrashed()->findOrFail($pipelineId);
        Gate::authorize('restore', $pipeline);
        $pipeline->restore();

        return redirect()->route($this->routeName('workflow-pipelines.index'), ['filter' => 'all'])
            ->with('success', __('workflow-pipelines::pipelines.restored'));
    }

    /** @return list<array{type: string, label: string}> */
    private function transactionTypes(): array
    {
        $types = [];

        foreach (app(ApprovableModelRegistry::class)->all() as $modelClass) {
            $model = new $modelClass;

            if (! $model instanceof Model) {
                continue;
            }

            $types[] = [
                'type' => $model->getMorphClass(),
                'label' => $modelClass::workflowModelLabel(),
            ];
        }

        return $types;
    }

    /** @return list<array{id: int|string, name: string}> */
    private function organizations(): array
    {
        $model = config('workflow-pipelines.models.organization');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        return $model::query()->orderBy('name')->get()->map(static fn (Model $organization): array => [
            'id' => $organization->getKey(),
            'name' => (string) ($organization->getAttribute('name') ?? $organization->getKey()),
        ])->all();
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
