<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

class TransactionWorkflow extends Model
{
    use SoftDeletes;

    protected $table = 'transaction_workflows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unlocked_fields' => 'array',
            'submitted_at' => 'datetime',
            'reverted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_action_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class)->withTrashed();
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function resumableStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'resumable_step_id');
    }

    /** @return BelongsTo<WorkflowStepRule, $this> */
    public function revertedByRule(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepRule::class, 'reverted_by_rule_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function displayStatus(): BelongsTo
    {
        /** @var class-string<Model> $statusModel */
        $statusModel = (string) config('workflow-pipelines.models.status');

        return $this->belongsTo($statusModel, 'status_id');
    }

    public function canBeActedOnBy(EloquentModel $user): bool
    {
        if (! in_array($this->status, ['draft', 'pending'], true)) {
            return false;
        }

        if (app(WorkflowAuthorizer::class)->isSuperAdministrator($user)) {
            return true;
        }

        $step = $this->currentStep;

        if ($step === null) {
            return false;
        }

        $approvable = $this->approvable;

        if (! $approvable instanceof EloquentModel || ! method_exists($approvable, 'stepApprovers')) {
            return false;
        }

        $assignment = $approvable->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', $this->cycle)
            ->where('status', 'pending')
            ->first();

        if ($assignment === null) {
            return false;
        }

        if ($step->assignable) {
            return (string) $assignment->approver_id === (string) $user->getKey();
        }

        $hasRole = method_exists($user, 'roles') && $user->roles()->whereKey($step->role_id)->exists();
        $unitId = $approvable instanceof EloquentModel && method_exists($approvable, 'workflowUnitId')
            ? $approvable->workflowUnitId()
            : null;
        $membership = (string) config('workflow-pipelines.organization.membership_relation', 'organizations');
        $belongsToUnit = $unitId !== null
            && method_exists($user, $membership)
            && $user->{$membership}()->whereKey($unitId)->exists();

        return $hasRole && $belongsToUnit;
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
