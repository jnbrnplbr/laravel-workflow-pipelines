<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;

interface Approvable
{
    /** @return array<string, string> */
    public static function workflowEditableFields(): array;

    public static function workflowModelLabel(): string;

    public static function workflowUnitColumn(): string;

    public function workflowUnitId(): ?int;

    public function workflowCreatorId(): ?int;

    public function workflowTitle(): string;

    public function workflowUrl(): ?string;

    /** @return MorphOne<TransactionWorkflow, $this> */
    public function transactionWorkflow(): MorphOne;
}
