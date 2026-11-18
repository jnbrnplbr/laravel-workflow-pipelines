<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;

final class WorkflowResolver
{
    /**
     * Resolve a unit-specific pipeline first, then the global default.
     * Pipelines whose workflow is soft deleted are ignored.
     *
     * @param  Model|class-string<Model&Approvable>  $model
     */
    public function resolve(Model|string $model, ?int $unitId = null): ?Workflow
    {
        $instance = is_string($model) ? new $model : $model;

        if (! $instance instanceof Approvable) {
            throw new InvalidArgumentException('Workflow resolution requires an Approvable model.');
        }

        $unitId ??= $instance->workflowUnitId();

        $pipelines = WorkflowPipeline::query()
            ->where('model_type', $instance->getMorphClass())
            ->where(function ($query) use ($unitId): void {
                if ($unitId !== null) {
                    $query->where('unit_id', $unitId)->orWhereNull('unit_id');

                    return;
                }

                $query->whereNull('unit_id');
            })
            ->orderByRaw('CASE WHEN unit_id IS NULL THEN 1 ELSE 0 END')
            ->with('workflow')
            ->get();

        foreach ($pipelines as $pipeline) {
            $workflow = $pipeline->workflow;

            if ($workflow instanceof Workflow && ! $workflow->trashed()) {
                return $workflow;
            }
        }

        return null;
    }
}
