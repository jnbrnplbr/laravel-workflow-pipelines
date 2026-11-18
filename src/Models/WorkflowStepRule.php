<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStepRule extends Model
{
    protected $table = 'workflow_step_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target_step_ids' => 'array',
            'editable_fields' => 'array',
            'reset_approval' => 'boolean',
            'model_types' => 'array',
        ];
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /** @return HasMany<TransactionWorkflow, $this> */
    public function revertedTransactions(): HasMany
    {
        return $this->hasMany(TransactionWorkflow::class, 'reverted_by_rule_id');
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
