<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Events;

use Illuminate\Database\Eloquent\Model;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;

abstract class WorkflowEvent
{
    public function __construct(
        public readonly Model $transaction,
        public readonly TransactionWorkflow $workflowInstance,
        public readonly WorkflowStep $step,
        public readonly Model $actor,
        public readonly string $action,
    ) {}
}
