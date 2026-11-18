<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Actions\DuplicateWorkflowAction;
use Jnbrnplbr\WorkflowPipelines\Actions\SyncWorkflowStepSettingsAction;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Policies\WorkflowPolicy;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;
use Jnbrnplbr\WorkflowPipelines\Tests\Fixtures\FixtureUser;

function workflowAdminStepData(int $roleId, int $statusId, int $sequence, string $key): array
{
    return [
        'client_key' => $key,
        'sequence' => $sequence,
        'role_id' => $roleId,
        'assignable' => false,
        'editable' => false,
        'status_id' => $statusId,
        'rules' => [],
    ];
}

function workflowAdminReferenceData(): array
{
    $roleId = DB::table('roles')->insertGetId(['name' => 'Reviewer']);
    $statusOne = DB::table('statuses')->insertGetId(['name' => 'Draft Review']);
    $statusTwo = DB::table('statuses')->insertGetId(['name' => 'Final Review']);

    return [$roleId, $statusOne, $statusTwo];
}

it('synchronizes ordered workflow steps and maps client keys to persisted rule targets', function (): void {
    [$roleId, $statusOne, $statusTwo] = workflowAdminReferenceData();
    $workflow = Workflow::create(['name' => 'Nested settings']);
    $steps = [
        workflowAdminStepData($roleId, $statusOne, 1, 'first'),
        workflowAdminStepData($roleId, $statusTwo, 2, 'second'),
    ];
    $steps[1]['rules'] = [[
        'action' => 'revert',
        'label' => 'Fix title',
        'target_step_ids' => ['first'],
        'editable_fields' => ['title'],
        'reset_approval' => false,
        'model_types' => [],
    ]];
    $steps[1]['notifications'] = [];

    app(SyncWorkflowStepSettingsAction::class)->execute($workflow, $steps);

    $savedSteps = $workflow->steps()->get();
    $rule = $savedSteps[1]->rules()->first();

    expect($savedSteps)->toHaveCount(2)
        ->and($savedSteps[0]->sequence)->toBe(1)
        ->and($rule->target_step_ids)->toBe([(int) $savedSteps[0]->getKey()])
        ->and($savedSteps[1]->notifications()->count())->toBe(1);
});

it('duplicates workflow rules and remaps their step targets', function (): void {
    [$roleId, $statusOne, $statusTwo] = workflowAdminReferenceData();
    $workflow = Workflow::create(['name' => 'Original']);
    $steps = [
        workflowAdminStepData($roleId, $statusOne, 1, 'first'),
        workflowAdminStepData($roleId, $statusTwo, 2, 'second'),
    ];
    $steps[1]['rules'] = [[
        'action' => 'revert',
        'label' => 'Fix title',
        'target_step_ids' => ['first'],
        'editable_fields' => ['title'],
        'reset_approval' => true,
        'model_types' => [],
    ]];
    app(SyncWorkflowStepSettingsAction::class)->execute($workflow, $steps);

    $copy = app(DuplicateWorkflowAction::class)->execute($workflow, 'Copy');
    $sourceFirstStepId = $workflow->steps()->orderBy('sequence')->first()->getKey();
    $copyFirstStep = $copy->steps()->orderBy('sequence')->first();
    $copyRule = $copy->steps()->orderBy('sequence')->get()[1]->rules()->first();

    expect($copy->name)->toBe('Copy')
        ->and($copyFirstStep->getKey())->not->toBe($sourceFirstStepId)
        ->and($copyRule->target_step_ids)->toBe([(int) $copyFirstStep->getKey()])
        ->and($copyRule->reset_approval)->toBeTrue();
});

it('locks used workflows and refuses to delete workflows referenced by active pipelines', function (): void {
    [$roleId, $statusId] = array_slice(workflowAdminReferenceData(), 0, 2);
    $workflow = Workflow::create(['name' => 'Locked']);
    $step = WorkflowStep::create([
        'workflow_id' => $workflow->getKey(),
        'role_id' => $roleId,
        'sequence' => 1,
        'assignable' => false,
        'editable' => false,
        'status_id' => $statusId,
    ]);
    TransactionWorkflow::create([
        'approvable_type' => 'fixture-transaction',
        'approvable_id' => 700,
        'workflow_id' => $workflow->getKey(),
        'current_step_id' => $step->getKey(),
        'status_id' => $statusId,
        'status' => 'draft',
        'cycle' => 1,
    ]);
    config(['workflow-pipelines.super_admin' => static fn (Model $user): bool => true]);
    $policy = new WorkflowPolicy(app(WorkflowAuthorizer::class));
    $user = new FixtureUser;

    expect($policy->update($user, $workflow))->toBeFalse();

    WorkflowPipeline::create([
        'model_type' => 'fixture-transaction',
        'unit_id' => null,
        'workflow_id' => $workflow->getKey(),
    ]);

    expect($policy->delete($user, $workflow))->toBeFalse();
});

it('denies workflow and pipeline administration routes without index permission', function (): void {
    config(['workflow-pipelines.super_admin' => static fn (Model $user): bool => false]);
    $user = FixtureUser::create(['name' => 'Reader']);

    $this->actingAs($user)->get('/development/pipelines/workflows')->assertForbidden();
    $this->actingAs($user)->get('/development/pipelines/workflow-pipelines')->assertForbidden();
    $this->actingAs($user)->post('/development/pipelines/workflows', [])->assertForbidden();
});
