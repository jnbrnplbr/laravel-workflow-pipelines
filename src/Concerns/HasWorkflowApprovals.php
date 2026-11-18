<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApprover;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApproverLog;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

/** @mixin Model */
trait HasWorkflowApprovals
{
    /** @return array<string, string> */
    public static function workflowEditableFields(): array
    {
        return [];
    }

    public static function workflowModelLabel(): string
    {
        return class_basename(static::class);
    }

    public static function workflowUnitColumn(): string
    {
        return 'unit_id';
    }

    public function workflowUnitId(): ?int
    {
        $value = $this->getAttribute(static::workflowUnitColumn());

        return $value === null ? null : (int) $value;
    }

    public function workflowCreatorId(): ?int
    {
        $value = $this->getAttribute('created_by');

        return $value === null ? null : (int) $value;
    }

    public function workflowTitle(): string
    {
        foreach (['title', 'name'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return static::workflowModelLabel().' #'.(string) $this->getKey();
    }

    public function workflowUrl(): ?string
    {
        return null;
    }

    /** @return MorphOne<TransactionWorkflow, $this> */
    public function transactionWorkflow(): MorphOne
    {
        return $this->morphOne(TransactionWorkflow::class, 'approvable');
    }

    /** @return MorphMany<WorkflowStepApprover, $this> */
    public function stepApprovers(): MorphMany
    {
        return $this->morphMany(WorkflowStepApprover::class, 'approvable');
    }

    /** @return MorphMany<WorkflowStepApproverLog, $this> */
    public function workflowLogs(): MorphMany
    {
        return $this->morphMany(WorkflowStepApproverLog::class, 'approvable')->orderBy('created_at');
    }

    public function getWorkflowInstance(): ?TransactionWorkflow
    {
        return $this->transactionWorkflow()->with([
            'workflow',
            'currentStep.role',
            'currentStep.displayStatus',
            'resumableStep',
            'revertedByRule',
        ])->first();
    }

    public function isDraft(): bool
    {
        return $this->getWorkflowInstance()?->status === 'draft';
    }

    public function isPending(): bool
    {
        return $this->getWorkflowInstance()?->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->getWorkflowInstance()?->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->getWorkflowInstance()?->status === 'rejected';
    }

    public function isReverted(): bool
    {
        $instance = $this->getWorkflowInstance();

        return $instance?->status === 'pending' && $instance->unlocked_fields !== null;
    }

    /**
     * @return array{all: bool, fields: list<string>}
     */
    public function workflowEditScope(Model $user): array
    {
        $none = ['all' => false, 'fields' => []];
        $instance = $this->getWorkflowInstance();

        if ($instance === null) {
            return $none;
        }

        if ($this->isSuperAdministrator($user)) {
            return ['all' => true, 'fields' => []];
        }

        if (! in_array($instance->status, ['draft', 'pending'], true)) {
            return $none;
        }

        $step = $instance->currentStep;

        if ($step === null || ! $this->isCurrentHandler($user, $instance, $step)) {
            return $none;
        }

        if ($step->editable) {
            return ['all' => true, 'fields' => []];
        }

        if ($instance->unlocked_fields !== null) {
            $fields = array_values(array_intersect(
                array_keys(static::workflowEditableFields()),
                array_map('strval', $instance->unlocked_fields),
            ));

            return ['all' => false, 'fields' => $fields];
        }

        $isStepOne = (int) $step->sequence === 1;
        $wasReopened = $instance->status === 'pending'
            && $isStepOne
            && $this->workflowLogs()->where('action', 'reopened')->exists();

        if ($isStepOne && ($instance->status === 'draft' || $wasReopened)) {
            return ['all' => true, 'fields' => []];
        }

        return $none;
    }

    public function canEditWorkflowField(Model $user, string $key): bool
    {
        $scope = $this->workflowEditScope($user);

        return $scope['all'] || in_array($key, $scope['fields'], true);
    }

    public function canEditAnyWorkflowField(Model $user): bool
    {
        $scope = $this->workflowEditScope($user);

        return $scope['all'] || $scope['fields'] !== [];
    }

    /**
     * Constrain transactions to actionable assignments without loading rows into PHP.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingApprovalFor(Builder $query, Model $user): Builder
    {
        $model = $query->getModel();
        $workflowTable = (new TransactionWorkflow)->getTable();
        $assignmentTable = (new WorkflowStepApprover)->getTable();
        $stepTable = config('workflow-pipelines.table_prefix', '').'workflow_steps';
        $userKey = $user->getKey();
        $morphType = $model->getMorphClass();
        $userRoleQuery = method_exists($user, 'roles') ? $user->roles()->select('id') : null;
        $membershipRelation = (string) config('workflow-pipelines.organization.membership_relation', 'organizations');
        $membershipQuery = method_exists($user, $membershipRelation)
            ? $user->{$membershipRelation}()->select('id')
            : null;

        return $query->whereExists(function ($instanceQuery) use (
            $workflowTable,
            $assignmentTable,
            $stepTable,
            $userKey,
            $morphType,
            $userRoleQuery,
            $membershipQuery,
            $model,
        ): void {
            $instanceQuery->selectRaw('1')
                ->from($workflowTable.' as pending_workflow')
                ->whereColumn('pending_workflow.approvable_id', $model->qualifyColumn($model->getKeyName()))
                ->where('pending_workflow.approvable_type', $morphType)
                ->whereNull('pending_workflow.deleted_at')
                ->whereIn('pending_workflow.status', ['draft', 'pending'])
                ->whereExists(function ($assignmentQuery) use (
                    $assignmentTable,
                    $stepTable,
                    $userKey,
                    $userRoleQuery,
                    $membershipQuery,
                    $model,
                ): void {
                    $assignmentQuery->selectRaw('1')
                        ->from($assignmentTable.' as pending_assignment')
                        ->join($stepTable.' as pending_step', 'pending_step.id', '=', 'pending_assignment.workflow_step_id')
                        ->whereColumn('pending_assignment.approvable_type', 'pending_workflow.approvable_type')
                        ->whereColumn('pending_assignment.approvable_id', 'pending_workflow.approvable_id')
                        ->whereColumn('pending_assignment.workflow_step_id', 'pending_workflow.current_step_id')
                        ->whereColumn('pending_assignment.cycle', 'pending_workflow.cycle')
                        ->where('pending_assignment.status', 'pending')
                        ->where(function ($handlerQuery) use ($userKey, $userRoleQuery, $membershipQuery, $model): void {
                            $handlerQuery->where(function ($assignedQuery) use ($userKey): void {
                                $assignedQuery->where('pending_step.assignable', true)
                                    ->where('pending_assignment.approver_id', $userKey);
                            });

                            if ($userRoleQuery !== null && $membershipQuery !== null) {
                                $unitColumn = (string) $model::workflowUnitColumn();

                                $handlerQuery->orWhere(function ($poolQuery) use ($userRoleQuery, $membershipQuery, $model, $unitColumn): void {
                                    $poolQuery->where('pending_step.assignable', false)
                                        ->whereNull('pending_assignment.approver_id')
                                        ->whereIn('pending_step.role_id', $userRoleQuery)
                                        ->whereIn($model->qualifyColumn($unitColumn), $membershipQuery);
                                });
                            }
                        });
                });
        });
    }

    /** @param Builder<static> $query @return Builder<static> */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('transactionWorkflow', static fn (Builder $workflow): Builder => $workflow->where('status', 'approved'));
    }

    /** @param Builder<static> $query @return Builder<static> */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->whereHas('transactionWorkflow', static fn (Builder $workflow): Builder => $workflow->where('status', 'draft'));
    }

    protected static function bootHasWorkflowApprovals(): void
    {
        static::deleting(static function (Model $model): void {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
            $forceDeleting = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

            if (! $usesSoftDeletes || $forceDeleting) {
                throw new LogicException('Approvable transactions must use soft deletes and cannot be force deleted.');
            }
        });

        static::deleted(static function (Model $model): void {
            $model->transactionWorkflow()->delete();
        });

        static::restored(static function (Model $model): void {
            $instance = $model->transactionWorkflow()->withTrashed()->first();

            if ($instance !== null && $instance->trashed()) {
                $instance->restore();
            }
        });
    }

    private function isSuperAdministrator(Model $user): bool
    {
        return app(WorkflowAuthorizer::class)->isSuperAdministrator($user);
    }

    private function isCurrentHandler(Model $user, TransactionWorkflow $instance, Model $step): bool
    {
        $assignment = $this->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', $instance->cycle)
            ->where('status', 'pending')
            ->first();

        if ($assignment === null) {
            return false;
        }

        if ((bool) $step->getAttribute('assignable')) {
            return (string) $assignment->approver_id === (string) $user->getKey();
        }

        $roleId = $step->getAttribute('role_id');
        $hasRole = method_exists($user, 'roles') && $user->roles()->whereKey($roleId)->exists();
        $unitId = $this->workflowUnitId();
        $membershipRelation = (string) config('workflow-pipelines.organization.membership_relation', 'organizations');
        $belongsToUnit = $unitId !== null
            && method_exists($user, $membershipRelation)
            && $user->{$membershipRelation}()->whereKey($unitId)->exists();

        return $hasRole && $belongsToUnit;
    }
}
