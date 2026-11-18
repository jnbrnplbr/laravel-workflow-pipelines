<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property-read Collection<int, WorkflowStep> $steps */
class Workflow extends Model
{
    use SoftDeletes;

    protected $table = 'workflows';

    protected $guarded = ['id'];

    /** @return HasMany<WorkflowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sequence');
    }

    /** @return HasMany<WorkflowPipeline, $this> */
    public function pipelines(): HasMany
    {
        return $this->hasMany(WorkflowPipeline::class);
    }

    /** @return HasMany<TransactionWorkflow, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(TransactionWorkflow::class)->withTrashed();
    }

    public function isLocked(): bool
    {
        return $this->transactions()->exists();
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
