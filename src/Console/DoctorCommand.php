<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApprover;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApproverLog;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepNotification;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepRule;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'workflow-pipelines:doctor';

    protected $description = 'Check workflow pipelines host configuration and database setup';

    private int $failures = 0;

    public function handle(): int
    {
        $this->line('Checking Laravel Workflow Pipelines setup');
        $morphMap = Relation::morphMap();
        $registeredClasses = array_values($morphMap);
        $models = [];

        foreach (['user', 'role', 'organization', 'status'] as $key) {
            $class = config("workflow-pipelines.models.{$key}");

            if (! is_string($class) || $class === '') {
                $this->check(false, "Config model [{$key}] is configured.", "Config model [{$key}] is not configured.");

                continue;
            }

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                $this->check(false, "Configured [{$key}] model [{$class}] does not exist or is not an Eloquent model.");

                continue;
            }

            $models[$key] = $class;
            $this->check(true, "Configured [{$key}] model exists: {$class}");
            $table = (string) config("workflow-pipelines.tables.{$key}", (new $class)->getTable());
            $this->check(
                Schema::hasTable($table),
                "Configured [{$key}] database table [{$table}] exists.",
                "Configured [{$key}] database table [{$table}] is missing.",
            );
        }

        foreach ([
            ...array_values($models),
            Workflow::class,
            WorkflowStep::class,
            WorkflowStepRule::class,
            WorkflowStepNotification::class,
            WorkflowPipeline::class,
            TransactionWorkflow::class,
            WorkflowStepApprover::class,
            WorkflowStepApproverLog::class,
        ] as $class) {
            $this->check(
                in_array($class, $registeredClasses, true),
                "Morph map includes [{$class}].",
                "Morph map is missing [{$class}]. Register morph aliases before saving polymorphic data.",
            );
        }

        try {
            $approvables = app(ApprovableModelRegistry::class)->all();
        } catch (Throwable $exception) {
            $this->check(false, 'Approvable registry is valid.', 'Could not load approvable models: '.$exception->getMessage());
            $approvables = [];
        }

        foreach ($approvables as $modelClass) {
            $model = new $modelClass;

            if (! $model instanceof Model) {
                $this->check(false, "Approvable [{$modelClass}] is an Eloquent model.");

                continue;
            }

            $alias = $model->getMorphClass();
            $this->check(
                in_array($modelClass, $registeredClasses, true),
                "Morph map includes approvable [{$modelClass}].",
                "Morph map is missing approvable [{$modelClass}] (alias [{$alias}]).",
            );

            try {
                $hasGlobalPipeline = WorkflowPipeline::query()
                    ->where('model_type', $alias)
                    ->whereNull('unit_id')
                    ->exists();
                $this->check(
                    $hasGlobalPipeline,
                    "A global pipeline exists for [{$alias}].",
                    "No active global pipeline exists for transaction type [{$alias}].",
                );
            } catch (Throwable $exception) {
                $this->check(false, "Global pipeline can be checked for [{$alias}].", $exception->getMessage());
            }
        }

        $this->checkWorkflowsHaveSteps();
        $this->checkDuplicateAssignments();

        if ($this->failures > 0) {
            $this->components->error("Doctor found {$this->failures} issue(s).");

            return self::FAILURE;
        }

        $this->components->info('Doctor found no setup issues.');

        return self::SUCCESS;
    }

    private function checkWorkflowsHaveSteps(): void
    {
        try {
            $emptyCount = Workflow::query()->doesntHave('steps')->count();
            $this->check($emptyCount === 0, 'Every active workflow has at least one step.', "Found {$emptyCount} workflow(s) without steps.");
        } catch (Throwable $exception) {
            $this->check(false, 'Workflow steps can be checked.', $exception->getMessage());
        }
    }

    private function checkDuplicateAssignments(): void
    {
        $table = (new WorkflowStepApprover)->getTable();

        if (! Schema::hasTable($table)) {
            $this->check(false, "Assignment table [{$table}] exists.", "Assignment table [{$table}] is missing; run package migrations.");

            return;
        }

        try {
            $duplicates = DB::table($table)
                ->select(['approvable_type', 'approvable_id', 'workflow_step_id', 'cycle'])
                ->selectRaw('COUNT(*) as row_count')
                ->groupBy(['approvable_type', 'approvable_id', 'workflow_step_id', 'cycle'])
                ->havingRaw('COUNT(*) > 1')
                ->limit(20)
                ->get();
            $this->check(
                $duplicates->isEmpty(),
                'No duplicate workflow assignment rows exist.',
                'Duplicate workflow assignment keys found: '.json_encode($duplicates->toArray(), JSON_UNESCAPED_SLASHES),
            );
        } catch (Throwable $exception) {
            $this->check(false, 'Assignment duplicates can be checked.', $exception->getMessage());
        }
    }

    private function check(bool $passed, string $success, ?string $failure = null): void
    {
        if ($passed) {
            $this->components->twoColumnDetail($success, '<fg=green;options=bold>OK</>');

            return;
        }

        $this->failures++;
        $this->components->twoColumnDetail($failure ?? $success, '<fg=red;options=bold>FAIL</>');
    }
}
