<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStepNotification extends Model
{
    protected $table = 'workflow_step_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'recipients' => 'array',
            'emails' => 'array',
            'model_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
