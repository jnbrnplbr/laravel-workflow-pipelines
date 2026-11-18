<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowPipeline extends Model
{
    use SoftDeletes;

    protected $table = 'workflow_pipelines';

    protected $guarded = ['id'];

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class)->withTrashed();
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
