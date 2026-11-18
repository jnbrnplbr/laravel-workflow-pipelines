<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class WorkflowStepApproverLog extends Model
{
    protected $table = 'workflow_step_approver_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Workflow approver logs are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Workflow approver logs are append-only.');
        });
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<WorkflowStepApprover, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepApprover::class, 'workflow_step_approver_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function actor(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('workflow-pipelines.models.user');

        return $this->belongsTo($userModel, 'approver_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function revertedToStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'reverted_to_step_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function resumeStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'resume_step_id');
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
