<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowResolver;
use Jnbrnplbr\WorkflowPipelines\Tests\Fixtures\FixtureTransaction;

it('resolves a unit pipeline before the global pipeline and skips deleted workflows', function (): void {
    $globalWorkflow = Workflow::create(['name' => 'Global']);
    $unitWorkflow = Workflow::create(['name' => 'Unit']);
    $modelType = (new FixtureTransaction)->getMorphClass();

    WorkflowPipeline::create([
        'model_type' => $modelType,
        'unit_id' => null,
        'workflow_id' => $globalWorkflow->getKey(),
    ]);

    WorkflowPipeline::create([
        'model_type' => $modelType,
        'unit_id' => 41,
        'workflow_id' => $unitWorkflow->getKey(),
    ]);

    $transaction = new FixtureTransaction(['unit_id' => 41]);
    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolve($transaction)?->is($unitWorkflow))->toBeTrue();

    $unitWorkflow->delete();

    expect($resolver->resolve($transaction)?->is($globalWorkflow))->toBeTrue();
});

it('creates a default database notification for each workflow step', function (): void {
    $workflow = Workflow::create(['name' => 'Approval']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'Reviewer']);
    $statusId = DB::table('statuses')->insertGetId(['name' => 'For Review']);

    $step = WorkflowStep::create([
        'workflow_id' => $workflow->getKey(),
        'role_id' => $roleId,
        'sequence' => 1,
        'assignable' => false,
        'editable' => false,
        'status_id' => $statusId,
    ]);

    expect($step->notifications()->first()?->only(['channel', 'events', 'recipients', 'is_active']))
        ->toBe([
            'channel' => 'database',
            'events' => ['approved', 'reverted', 'rejected'],
            'recipients' => ['next_handlers', 'creator'],
            'is_active' => true,
        ]);
});

it('registers explicit approvable transaction models', function (): void {
    $registry = app(ApprovableModelRegistry::class);
    $registry->register(FixtureTransaction::class);

    expect($registry->all())->toContain(FixtureTransaction::class);
});
