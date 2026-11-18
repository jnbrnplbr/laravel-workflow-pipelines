<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Jnbrnplbr\WorkflowPipelines\Exceptions\WorkflowActionException;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepNotification;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepRule;
use Jnbrnplbr\WorkflowPipelines\Notifications\WorkflowStepActionNotification;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowEngine;
use Jnbrnplbr\WorkflowPipelines\Tests\Fixtures\FixtureTransaction;
use Jnbrnplbr\WorkflowPipelines\Tests\Fixtures\FixtureUser;

beforeEach(function (): void {
    config(['workflow-pipelines.super_admin' => static fn (Model $user): bool => true]);
});

/** @return array{Workflow, list<WorkflowStep>} */
function createWorkflowWithSteps(int $count = 2): array
{
    $roleId = DB::table('roles')->insertGetId(['name' => 'Reviewer']);
    $workflow = Workflow::create(['name' => 'Approval '.uniqid()]);
    $steps = [];

    for ($sequence = 1; $sequence <= $count; $sequence++) {
        $statusId = DB::table('statuses')->insertGetId(['name' => 'Review '.$sequence]);
        $steps[] = WorkflowStep::create([
            'workflow_id' => $workflow->getKey(),
            'role_id' => $roleId,
            'sequence' => $sequence,
            'assignable' => false,
            'editable' => false,
            'status_id' => $statusId,
        ]);
    }

    return [$workflow, $steps];
}

it('starts as a draft then approves through each step to completion', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps();
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($transaction, $workflow, [], $actor);

    expect($instance->status)->toBe('draft')
        ->and($instance->current_step_id)->toBe($steps[0]->getKey())
        ->and($transaction->workflowLogs()->pluck('action')->all())->toBe(['created']);

    $instance = $engine->approve($transaction, $actor);

    expect($instance->status)->toBe('pending')
        ->and($instance->current_step_id)->toBe($steps[1]->getKey())
        ->and((int) $instance->submitted_by)->toBe((int) $actor->getKey());

    $instance = $engine->approve($transaction, $actor);

    expect($instance->status)->toBe('approved')
        ->and($instance->completed_at)->not->toBeNull()
        ->and($transaction->workflowLogs()->pluck('action')->all())->toBe(['created', 'submitted', 'approved']);
});

it('uses the same revert options for validation and starts a new approval cycle', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps();
    $rule = WorkflowStepRule::create([
        'workflow_step_id' => $steps[1]->getKey(),
        'action' => 'revert',
        'label' => 'Correct request',
        'target_step_ids' => [$steps[0]->getKey()],
        'editable_fields' => ['title'],
        'reset_approval' => false,
    ]);
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);
    $engine->start($transaction, $workflow, [], $actor);
    $engine->approve($transaction, $actor);

    $options = $engine->revertOptions($transaction);
    expect($options)->toHaveCount(1)
        ->and($options[0]['rule_id'])->toBe((int) $rule->getKey())
        ->and($options[0]['target_step_ids'])->toBe([(int) $steps[0]->getKey()]);

    $instance = $engine->revert($transaction, $actor, (int) $steps[0]->getKey(), (int) $rule->getKey(), ['title']);

    expect($instance->cycle)->toBe(2)
        ->and($instance->current_step_id)->toBe($steps[0]->getKey())
        ->and($instance->resumable_step_id)->toBe($steps[1]->getKey())
        ->and($instance->unlocked_fields)->toBe(['title'])
        ->and($instance->reverted_by_rule_id)->toBe($rule->getKey());

    $instance = $engine->approve($transaction, $actor);

    expect($instance->current_step_id)->toBe($steps[1]->getKey())
        ->and($instance->unlocked_fields)->toBeNull()
        ->and($instance->resumable_step_id)->toBeNull();
});

it('requires rejection and reopen remarks and starts reopened transactions at step one', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps();
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);
    $engine->start($transaction, $workflow, [], $actor);
    $engine->approve($transaction, $actor);

    expect(fn () => $engine->reject($transaction, $actor, '   '))->toThrow(WorkflowActionException::class);
    $rejected = $engine->reject($transaction, $actor, 'Insufficient details');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->current_step_id)->toBe($steps[1]->getKey());

    expect(fn () => $engine->reopen($transaction, $actor, ''))->toThrow(WorkflowActionException::class);
    $reopened = $engine->reopen($transaction, $actor, 'Please review again');

    expect($reopened->status)->toBe('pending')
        ->and($reopened->cycle)->toBe(2)
        ->and($reopened->current_step_id)->toBe($steps[0]->getKey())
        ->and($transaction->workflowLogs()->where('action', 'reopened')->exists())->toBeTrue();
});

it('logs overrides when a super administrator moves forward', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps(3);
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);
    $engine->start($transaction, $workflow, [], $actor);

    $instance = $engine->move($transaction, (int) $steps[2]->getKey(), $actor, 'Expedite this request');

    expect($instance->status)->toBe('approved')
        ->and($instance->current_step_id)->toBe($steps[2]->getKey())
        ->and($transaction->workflowLogs()->where('action', 'override')->count())->toBe(3)
        ->and($transaction->workflowLogs()->where('action', 'moved')->exists())->toBeTrue();
});

it('re-routes to a new workflow and resets assignments in a new cycle', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps();
    [$newWorkflow, $newSteps] = createWorkflowWithSteps(3);
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);
    $engine->start($transaction, $workflow, [], $actor);

    $instance = $engine->reroute($transaction, $newWorkflow, $actor, [], (int) $newSteps[1]->getKey(), 'New process');

    expect($instance->workflow_id)->toBe($newWorkflow->getKey())
        ->and($instance->current_step_id)->toBe($newSteps[1]->getKey())
        ->and($instance->cycle)->toBe(2)
        ->and($transaction->stepApprovers()->where('cycle', 2)->where('status', 'approved')->count())->toBe(1)
        ->and($transaction->workflowLogs()->where('action', 'rerouted')->exists())->toBeTrue();
});

it('sends configured notifications after an action commits', function (): void {
    [$workflow, $steps] = createWorkflowWithSteps();
    WorkflowStepNotification::create([
        'workflow_step_id' => $steps[0]->getKey(),
        'channel' => 'mail',
        'events' => ['approved'],
        'recipients' => [],
        'emails' => ['review@example.test'],
        'is_active' => true,
    ]);
    $transaction = FixtureTransaction::create(['unit_id' => 1, 'title' => 'Purchase request']);
    $actor = FixtureUser::create(['name' => 'Administrator']);
    $engine = app(WorkflowEngine::class);
    $engine->start($transaction, $workflow, [], $actor);
    Notification::fake();

    $instance = $engine->approve($transaction, $actor);

    expect($instance->status)->toBe('pending');
    Notification::assertSentOnDemand(WorkflowStepActionNotification::class);
});
