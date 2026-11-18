<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowStepApprover extends Model
{
    protected $table = 'workflow_step_approvers';

    protected $guarded = ['id'];

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function approver(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('workflow-pipelines.models.user');

        return $this->belongsTo($userModel, 'approver_id');
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
