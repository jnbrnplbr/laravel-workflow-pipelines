<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;
use Jnbrnplbr\WorkflowPipelines\Events\ApproverReassigned;
use Jnbrnplbr\WorkflowPipelines\Events\StepApproved;
use Jnbrnplbr\WorkflowPipelines\Events\StepRejected;
use Jnbrnplbr\WorkflowPipelines\Events\StepReverted;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowCompleted;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowEvent;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowMoved;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowReopened;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowRerouted;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowStarted;
use Jnbrnplbr\WorkflowPipelines\Exceptions\WorkflowActionException;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApprover;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepApproverLog;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepRule;
use Throwable;

final class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowNotifier $notifier,
        private readonly WorkflowAuthorizer $authorizer,
    ) {}

    /**
     * Create the immutable workflow instance and cycle-one assignments.
     *
     * @param  array<int|string, int|string|null>  $assignees  workflow step id => user id
     */
    public function start(Model $transaction, Workflow $workflow, array $assignees, Model $actor): TransactionWorkflow
    {
        $this->assertApprovable($transaction);

        return DB::transaction(function () use ($transaction, $workflow, $assignees, $actor): TransactionWorkflow {
            $existing = $transaction->transactionWorkflow()->withTrashed()->lockForUpdate()->first();

            if ($existing !== null) {
                throw new WorkflowActionException('A workflow has already been started for this transaction.');
            }

            $workflow = Workflow::query()->with(['steps.role', 'steps.displayStatus'])->find($workflow->getKey());

            if ($workflow === null || $workflow->steps->isEmpty()) {
                throw new WorkflowActionException('The selected workflow has no steps.', 'workflow_id');
            }

            $steps = $workflow->steps->sortBy('sequence')->values();
            $first = $steps->first();
            $instance = $transaction->transactionWorkflow()->create([
                'workflow_id' => $workflow->getKey(),
                'current_step_id' => $first->getKey(),
                'status_id' => $first->status_id,
                'status' => 'draft',
                'cycle' => 1,
            ]);

            foreach ($steps as $step) {
                $assigneeId = $step->assignable
                    ? $this->requiredAssignee($assignees, $step)
                    : null;

                if ($assigneeId !== null) {
                    $this->assertAssigneeIsValid($transaction, $step, $assigneeId);
                }

                $assignment = $transaction->stepApprovers()->create([
                    'workflow_step_id' => $step->getKey(),
                    'approver_id' => $assigneeId,
                    'cycle' => 1,
                    'status' => 'pending',
                ]);

                if ($step->is($first)) {
                    $this->writeLog($transaction, $step, $actor, 'created', 1, $assignment);
                }
            }

            $this->dispatchAfterCommit(new WorkflowStarted($transaction, $instance, $first, $actor, 'created'));

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    /**
     * @return list<array{rule_id: int|null, label: string|null, target_step_ids: list<int>, editable_fields: list<string>, reset_approval: bool}>
     */
    public function revertOptions(Model $transaction): array
    {
        $this->assertApprovable($transaction);
        $instance = $this->instance($transaction);
        $current = $instance->currentStep;

        if ($current === null) {
            return [];
        }

        $previousSteps = $instance->workflow->steps()
            ->where('sequence', '<', $current->sequence)
            ->orderByDesc('sequence')
            ->get();

        if ($previousSteps->isEmpty()) {
            return [];
        }

        $rules = $current->rules()->where('action', 'revert')->get()
            ->filter(fn (WorkflowStepRule $rule): bool => $this->ruleAppliesTo($rule, $transaction));

        if ($rules->isEmpty()) {
            $fields = array_keys($transaction::workflowEditableFields());

            return [[
                'rule_id' => null,
                'label' => null,
                'target_step_ids' => [(int) $previousSteps->first()->getKey()],
                'editable_fields' => array_values($fields),
                'reset_approval' => false,
            ]];
        }

        $previousIds = $previousSteps->modelKeys();
        $options = [];

        foreach ($rules as $rule) {
            $targetIds = array_values(array_filter(
                array_map('intval', $rule->target_step_ids ?? []),
                static fn (int $stepId): bool => in_array($stepId, $previousIds, true),
            ));

            if ($targetIds === []) {
                continue;
            }

            $registeredFields = array_keys($transaction::workflowEditableFields());
            $fields = $rule->editable_fields === null
                ? []
                : array_values(array_intersect($registeredFields, array_map('strval', $rule->editable_fields)));

            $options[] = [
                'rule_id' => (int) $rule->getKey(),
                'label' => $rule->label,
                'target_step_ids' => $targetIds,
                'editable_fields' => $fields,
                'reset_approval' => (bool) $rule->reset_approval,
            ];
        }

        return $options;
    }

    public function approve(Model $transaction, Model $actor): TransactionWorkflow
    {
        return DB::transaction(function () use ($transaction, $actor): TransactionWorkflow {
            [$instance, $step, $assignment] = $this->actionContext($transaction, $actor);
            $cycle = (int) $instance->cycle;
            $assignment->status = 'approved';
            $assignment->save();

            $action = (int) $step->sequence === 1 ? 'submitted' : 'approved';
            $this->writeLog($transaction, $step, $actor, $action, $cycle, $assignment);

            if ((int) $step->sequence === 1) {
                $instance->submitted_at = now();
                $instance->submitted_by = $actor->getKey();
            }

            $next = $instance->resumableStep;

            if ($next !== null) {
                $instance->resumable_step_id = null;
            } else {
                $next = $instance->workflow->steps()
                    ->where('sequence', '>', $step->sequence)
                    ->orderBy('sequence')
                    ->first();
            }

            $instance->unlocked_fields = null;
            $instance->reverted_by_rule_id = null;
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();

            if ($next === null) {
                $instance->status = 'approved';
                $instance->completed_at = now();
            } else {
                $this->ensureAssignment($transaction, $next, $cycle);
                $instance->status = 'pending';
                $instance->current_step_id = $next->getKey();
                $instance->status_id = $next->status_id;
            }

            $instance->save();
            $this->dispatchAfterCommit(new StepApproved($transaction, $instance, $step, $actor, $action), 'approved', $cycle, $step);

            if ($instance->status === 'approved') {
                $this->dispatchAfterCommit(new WorkflowCompleted($transaction, $instance, $step, $actor, 'approved'));
            }

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    public function revert(
        Model $transaction,
        Model $actor,
        int $targetStepId,
        ?int $ruleId = null,
        array $unlockedFields = [],
        ?string $remarks = null,
    ): TransactionWorkflow {
        return DB::transaction(function () use ($transaction, $actor, $targetStepId, $ruleId, $unlockedFields, $remarks): TransactionWorkflow {
            [$instance, $step, $assignment] = $this->actionContext($transaction, $actor);
            $options = $this->revertOptions($transaction);
            $option = collect($options)->first(fn (array $candidate): bool => $candidate['rule_id'] === $ruleId);

            if ($option === null || ! in_array($targetStepId, $option['target_step_ids'], true)) {
                throw new WorkflowActionException('Choose a valid earlier workflow step to return this transaction to.', 'target_step_id');
            }

            if (array_diff($unlockedFields, $option['editable_fields']) !== []) {
                throw new WorkflowActionException('One or more selected fields cannot be unlocked by this revert rule.', 'unlocked_fields');
            }

            $target = $instance->workflow->steps()->find($targetStepId);

            if ($target === null || (int) $target->sequence >= (int) $step->sequence) {
                throw new WorkflowActionException('The revert target must be an earlier step in this workflow.', 'target_step_id');
            }

            $cycle = (int) $instance->cycle;
            $nextCycle = $this->nextCycle($cycle);
            $assignment->status = 'reverted';
            $assignment->save();
            $this->writeLog($transaction, $step, $actor, 'reverted', $cycle, $assignment, $target, $option['reset_approval'] ? null : $step, $remarks);

            foreach ($instance->workflow->steps()
                ->whereBetween('sequence', [$target->sequence, $step->sequence])
                ->orderBy('sequence')
                ->get() as $cycleStep) {
                $this->createCycleAssignment($transaction, $cycleStep, $cycle, $nextCycle, 'pending');
            }

            $instance->cycle = $nextCycle;
            $instance->current_step_id = $target->getKey();
            $instance->resumable_step_id = $option['reset_approval'] ? null : $step->getKey();
            $instance->unlocked_fields = array_values(array_unique(array_map('strval', $unlockedFields)));
            $instance->reverted_by_rule_id = $ruleId;
            $instance->status_id = $target->status_id;
            $instance->status = 'pending';
            $instance->reverted_at = now();
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();

            $this->dispatchAfterCommit(new StepReverted($transaction, $instance, $step, $actor, 'reverted'), 'reverted', $cycle, $step);

            return $instance->fresh(['workflow', 'currentStep', 'resumableStep']);
        });
    }

    public function reject(Model $transaction, Model $actor, string $remarks): TransactionWorkflow
    {
        if (trim($remarks) === '') {
            throw new WorkflowActionException('A reason is required when rejecting a transaction.', 'remarks');
        }

        return DB::transaction(function () use ($transaction, $actor, $remarks): TransactionWorkflow {
            [$instance, $step, $assignment] = $this->actionContext($transaction, $actor);
            $assignment->status = 'rejected';
            $assignment->save();
            $this->writeLog($transaction, $step, $actor, 'rejected', (int) $instance->cycle, $assignment, remarks: $remarks);
            $instance->status = 'rejected';
            $instance->rejected_at = now();
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();

            $this->dispatchAfterCommit(new StepRejected($transaction, $instance, $step, $actor, 'rejected'), 'rejected', (int) $instance->cycle, $step);

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    public function reopen(Model $transaction, Model $actor, string $remarks): TransactionWorkflow
    {
        $this->requireRemarks($remarks);

        return DB::transaction(function () use ($transaction, $actor, $remarks): TransactionWorkflow {
            $instance = $this->lockedInstance($transaction);

            if ($instance->status !== 'rejected') {
                throw new WorkflowActionException('Only rejected transactions can be reopened.');
            }

            $this->assertSuperAdministrator($actor);
            $steps = $instance->workflow->steps()->orderBy('sequence')->get();
            $first = $steps->first();

            if ($first === null) {
                throw new WorkflowActionException('The workflow has no steps.');
            }

            $oldCycle = (int) $instance->cycle;
            $cycle = $this->nextCycle($oldCycle);

            foreach ($steps as $step) {
                $this->createCycleAssignment($transaction, $step, $oldCycle, $cycle, 'pending');
            }

            $instance->cycle = $cycle;
            $instance->current_step_id = $first->getKey();
            $instance->resumable_step_id = null;
            $instance->status_id = $first->status_id;
            $instance->status = 'pending';
            $instance->unlocked_fields = null;
            $instance->reverted_by_rule_id = null;
            $instance->rejected_at = null;
            $instance->completed_at = null;
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();

            $firstAssignment = $this->assignment($transaction, $first, $cycle);
            $this->writeLog($transaction, $first, $actor, 'reopened', $cycle, $firstAssignment, remarks: $remarks);
            $this->dispatchAfterCommit(new WorkflowReopened($transaction, $instance, $first, $actor, 'reopened'), 'reverted', $cycle, $first);

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    public function move(
        Model $transaction,
        int $targetStepId,
        Model $actor,
        string $remarks,
        array $unlockedFields = [],
    ): TransactionWorkflow {
        $this->requireRemarks($remarks);

        return DB::transaction(function () use ($transaction, $targetStepId, $actor, $remarks, $unlockedFields): TransactionWorkflow {
            $instance = $this->lockedInstance($transaction);
            $this->assertSuperAdministrator($actor);
            $this->assertActive($instance);
            $current = $instance->currentStep;
            $target = $instance->workflow->steps()->find($targetStepId);

            if ($current === null || $target === null) {
                throw new WorkflowActionException('Choose a step in this workflow.', 'target_step_id');
            }

            if ((int) $target->sequence === (int) $current->sequence) {
                throw new WorkflowActionException('Choose a different workflow step.', 'target_step_id');
            }

            if ((int) $target->sequence < (int) $current->sequence) {
                $registeredFields = array_keys($transaction::workflowEditableFields());

                if (array_diff($unlockedFields, $registeredFields) !== []) {
                    throw new WorkflowActionException('One or more selected fields are not registered as editable.', 'unlocked_fields');
                }

                $oldCycle = (int) $instance->cycle;
                $cycle = $this->nextCycle($oldCycle);
                $currentAssignment = $this->assignment($transaction, $current, $oldCycle);
                $currentAssignment->status = 'reverted';
                $currentAssignment->save();

                foreach ($instance->workflow->steps()
                    ->whereBetween('sequence', [$target->sequence, $current->sequence])
                    ->orderBy('sequence')
                    ->get() as $cycleStep) {
                    $this->createCycleAssignment($transaction, $cycleStep, $oldCycle, $cycle, 'pending');
                }

                $instance->cycle = $cycle;
                $instance->current_step_id = $target->getKey();
                $instance->resumable_step_id = $current->getKey();
                $instance->unlocked_fields = array_values(array_unique(array_map('strval', $unlockedFields)));
                $instance->reverted_by_rule_id = null;
                $instance->reverted_at = now();
                $instance->status_id = $target->status_id;
                $instance->status = 'pending';
                $instance->last_action_at = now();
                $instance->last_actor_id = $actor->getKey();
                $instance->save();

                $this->writeLog($transaction, $target, $actor, 'moved', $cycle, $this->assignment($transaction, $target, $cycle), $target, $current, $remarks);
                $this->dispatchAfterCommit(new WorkflowMoved($transaction, $instance, $target, $actor, 'moved'), 'reverted', (int) $instance->cycle, $current);

                return $instance->fresh(['workflow', 'currentStep', 'resumableStep']);
            }

            $oldCycle = (int) $instance->cycle;
            $wasDraft = $instance->status === 'draft';

            foreach ($instance->workflow->steps()
                ->whereBetween('sequence', [$current->sequence, $target->sequence])
                ->orderBy('sequence')
                ->get() as $skippedStep) {
                $assignment = $this->ensureAssignment($transaction, $skippedStep, $oldCycle);

                if ($assignment->status === 'approved') {
                    continue;
                }

                $assignment->status = 'approved';
                $assignment->save();
                $this->writeLog($transaction, $skippedStep, $actor, 'override', $oldCycle, $assignment, remarks: $remarks);
            }

            if ($wasDraft) {
                $instance->submitted_at = now();
                $instance->submitted_by = $actor->getKey();
            }

            $next = $instance->workflow->steps()
                ->where('sequence', '>', $target->sequence)
                ->orderBy('sequence')
                ->first();

            if ($next === null) {
                $instance->status = 'approved';
                $instance->completed_at = now();
                $instance->current_step_id = $target->getKey();
                $instance->status_id = $target->status_id;
            } else {
                $this->ensureAssignment($transaction, $next, $oldCycle);
                $instance->status = 'pending';
                $instance->current_step_id = $next->getKey();
                $instance->status_id = $next->status_id;
            }

            $instance->resumable_step_id = null;
            $instance->unlocked_fields = null;
            $instance->reverted_by_rule_id = null;
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();
            $this->writeLog($transaction, $target, $actor, 'moved', $oldCycle, $this->assignment($transaction, $target, $oldCycle), $target, remarks: $remarks);
            $this->dispatchAfterCommit(new WorkflowMoved($transaction, $instance, $target, $actor, 'moved'), 'approved', $oldCycle, $current);

            if ($instance->status === 'approved') {
                $this->dispatchAfterCommit(new WorkflowCompleted($transaction, $instance, $target, $actor, 'approved'));
            }

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    /**
     * @param  array<int|string, int|string|null>  $assignees  new workflow step id => user id
     */
    public function reroute(
        Model $transaction,
        Workflow $newWorkflow,
        Model $actor,
        array $assignees,
        ?int $targetStepId = null,
        ?string $remarks = null,
    ): TransactionWorkflow {
        return DB::transaction(function () use ($transaction, $newWorkflow, $actor, $assignees, $targetStepId, $remarks): TransactionWorkflow {
            $instance = $this->lockedInstance($transaction);
            $this->assertSuperAdministrator($actor);
            $this->assertActive($instance);
            $oldWorkflow = $instance->workflow;
            $oldStep = $instance->currentStep;
            $newWorkflow = Workflow::query()->with('steps')->find($newWorkflow->getKey());

            if ($newWorkflow === null || $newWorkflow->steps->isEmpty()) {
                throw new WorkflowActionException('Choose a workflow that has at least one step.', 'workflow_id');
            }

            $steps = $newWorkflow->steps->sortBy('sequence')->values();
            $target = $targetStepId === null ? $steps->first() : $steps->firstWhere('id', $targetStepId);

            if ($target === null) {
                throw new WorkflowActionException('Choose a restart step in the new workflow.', 'target_step_id');
            }

            $oldCycle = (int) $instance->cycle;
            $cycle = $this->nextCycle($oldCycle);

            if ($oldStep !== null) {
                $oldAssignment = $this->assignment($transaction, $oldStep, $oldCycle);
                $oldAssignment->status = 'reverted';
                $oldAssignment->save();
            }

            foreach ($steps as $step) {
                $assigneeId = $step->assignable ? $this->requiredAssignee($assignees, $step) : null;

                if ($assigneeId !== null) {
                    $this->assertAssigneeIsValid($transaction, $step, $assigneeId);
                }

                $status = (int) $step->sequence < (int) $target->sequence ? 'approved' : 'pending';
                $transaction->stepApprovers()->create([
                    'workflow_step_id' => $step->getKey(),
                    'approver_id' => $assigneeId,
                    'cycle' => $cycle,
                    'status' => $status,
                ]);
            }

            $instance->workflow_id = $newWorkflow->getKey();
            $instance->cycle = $cycle;
            $instance->current_step_id = $target->getKey();
            $instance->resumable_step_id = null;
            $instance->unlocked_fields = null;
            $instance->reverted_by_rule_id = null;
            $instance->status_id = $target->status_id;
            $instance->status = 'pending';
            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();

            $this->writeLog(
                $transaction,
                $target,
                $actor,
                'rerouted',
                $cycle,
                $this->assignment($transaction, $target, $cycle),
                $target,
                remarks: trim('Rerouted from '.$oldWorkflow->name.' to '.$newWorkflow->name.'. '.(string) $remarks),
            );

            $eventStep = $oldStep ?? $target;
            $this->dispatchAfterCommit(new WorkflowRerouted($transaction, $instance, $eventStep, $actor, 'rerouted'), 'reverted', $cycle, $eventStep);

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    /**
     * Reassign any not-yet-approved assignable steps in the active cycle.
     *
     * @param  array<int|string, int|string>  $assignees  workflow step id => user id
     */
    public function reassign(Model $transaction, Model $actor, array $assignees, ?string $remarks = null): TransactionWorkflow
    {
        return DB::transaction(function () use ($transaction, $actor, $assignees, $remarks): TransactionWorkflow {
            [$instance] = $this->actionContext($transaction, $actor);
            $current = $instance->currentStep;

            if ($current === null) {
                throw new WorkflowActionException('There is no current workflow step.');
            }

            $pendingSteps = $instance->workflow->steps()
                ->where('sequence', '>=', $current->sequence)
                ->orderBy('sequence')
                ->get();
            $changes = [];

            foreach ($pendingSteps as $step) {
                if (! $step->assignable) {
                    continue;
                }

                $assignment = $this->assignment($transaction, $step, (int) $instance->cycle);

                if ($assignment->status === 'approved') {
                    continue;
                }

                $key = (string) $step->getKey();

                if (! array_key_exists($key, $assignees) && ! array_key_exists((int) $key, $assignees)) {
                    throw new WorkflowActionException('Select an approver for every pending assignable step.', 'assignees');
                }

                $assigneeId = array_key_exists($key, $assignees) ? $assignees[$key] : $assignees[(int) $key];

                if (! is_int($assigneeId) && ! is_string($assigneeId)) {
                    throw new WorkflowActionException('Choose an approver for each pending assignable step.', 'assignees');
                }

                $this->assertAssigneeIsValid($transaction, $step, $assigneeId);

                if ((string) $assignment->approver_id === (string) $assigneeId) {
                    continue;
                }

                $changes[] = [$step, $assignment, $assigneeId];
            }

            if ($changes === []) {
                throw new WorkflowActionException('No pending approver assignments were changed.', 'assignees');
            }

            foreach ($changes as [$step, $assignment, $assigneeId]) {
                $assignment->approver_id = $assigneeId;
                $assignment->save();
                $this->writeLog($transaction, $step, $actor, 'reassigned', (int) $instance->cycle, $assignment, remarks: $remarks);
            }

            $instance->last_action_at = now();
            $instance->last_actor_id = $actor->getKey();
            $instance->save();

            if ($current !== null) {
                $this->dispatchAfterCommit(new ApproverReassigned($transaction, $instance, $current, $actor, 'reassigned'));
            }

            return $instance->fresh(['workflow', 'currentStep']);
        });
    }

    /** @return array{TransactionWorkflow, WorkflowStep, WorkflowStepApprover} */
    private function actionContext(Model $transaction, Model $actor): array
    {
        $this->assertApprovable($transaction);
        $instance = $this->lockedInstance($transaction);
        $this->assertActive($instance);
        $step = $instance->currentStep;

        if ($step === null) {
            throw new WorkflowActionException('The workflow has no current step.');
        }

        $assignment = $this->assignment($transaction, $step, (int) $instance->cycle);

        if ($assignment->status !== 'pending' || ! $instance->canBeActedOnBy($actor)) {
            throw new WorkflowActionException('You are not an approver for the current workflow step.');
        }

        return [$instance, $step, $assignment];
    }

    private function lockedInstance(Model $transaction): TransactionWorkflow
    {
        $this->assertApprovable($transaction);
        $instance = $transaction->transactionWorkflow()
            ->lockForUpdate()
            ->with(['workflow.steps.rules', 'currentStep', 'resumableStep'])
            ->first();

        if ($instance === null) {
            throw new WorkflowActionException('No workflow has been started for this transaction.');
        }

        return $instance;
    }

    private function instance(Model $transaction): TransactionWorkflow
    {
        $this->assertApprovable($transaction);
        $instance = $transaction->transactionWorkflow()->with(['workflow.steps.rules', 'currentStep', 'resumableStep'])->first();

        if ($instance === null) {
            throw new WorkflowActionException('No workflow has been started for this transaction.');
        }

        return $instance;
    }

    private function assertApprovable(Model $transaction): void
    {
        if (! $transaction instanceof Approvable) {
            throw new WorkflowActionException('The transaction model must implement the Approvable contract.');
        }
    }

    private function assertActive(TransactionWorkflow $instance): void
    {
        if (! in_array($instance->status, ['draft', 'pending'], true)) {
            throw new WorkflowActionException('This workflow is not active.');
        }
    }

    private function assertSuperAdministrator(Model $actor): void
    {
        if ($this->authorizer->isSuperAdministrator($actor)) {
            return;
        }

        throw new WorkflowActionException('Only a Super Administrator can perform this workflow override.');
    }

    private function assertAssigneeIsValid(Model $transaction, WorkflowStep $step, int|string $userId): void
    {
        /** @var class-string<Model> $userClass */
        $userClass = (string) config('workflow-pipelines.models.user');
        $user = $userClass::query()->find($userId);

        if ($user === null) {
            throw new WorkflowActionException('The selected approver does not exist.', 'assignees');
        }

        $hasRole = method_exists($user, 'roles') && $user->roles()->whereKey($step->role_id)->exists();
        $unitId = $transaction->workflowUnitId();
        $relation = (string) config('workflow-pipelines.organization.membership_relation', 'organizations');
        $belongsToUnit = $unitId !== null
            && method_exists($user, $relation)
            && $user->{$relation}()->whereKey($unitId)->exists();

        if (! $hasRole || ! $belongsToUnit) {
            throw new WorkflowActionException('The selected approver must hold the step role and belong to the transaction organization.', 'assignees');
        }
    }

    /** @param array<int|string, int|string|null> $assignees */
    private function requiredAssignee(array $assignees, WorkflowStep $step): int|string
    {
        $key = (string) $step->getKey();

        if (! array_key_exists($key, $assignees) && ! array_key_exists((int) $key, $assignees)) {
            throw new WorkflowActionException('An approver is required for every assignable step.', 'assignees');
        }

        $id = array_key_exists($key, $assignees) ? $assignees[$key] : $assignees[(int) $key];

        if ((! is_int($id) && ! is_string($id)) || $id === '') {
            throw new WorkflowActionException('An approver is required for every assignable step.', 'assignees');
        }

        return $id;
    }

    private function assignment(Model $transaction, WorkflowStep $step, int $cycle): WorkflowStepApprover
    {
        $assignment = $transaction->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', $cycle)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw new WorkflowActionException('The current step assignment is missing.');
        }

        return $assignment;
    }

    private function ensureAssignment(Model $transaction, WorkflowStep $step, int $cycle): WorkflowStepApprover
    {
        $assignment = $transaction->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', $cycle)
            ->first();

        if ($assignment !== null) {
            return $assignment;
        }

        $assigneeId = $step->assignable ? $this->latestAssigneeId($transaction, $step, $cycle) : null;

        if ($step->assignable && $assigneeId === null) {
            throw new WorkflowActionException('An approver is required for the next assignable step.', 'assignees');
        }

        return $transaction->stepApprovers()->create([
            'workflow_step_id' => $step->getKey(),
            'approver_id' => $assigneeId,
            'cycle' => $cycle,
            'status' => 'pending',
        ]);
    }

    private function createCycleAssignment(
        Model $transaction,
        WorkflowStep $step,
        int $fromCycle,
        int $cycle,
        string $status,
    ): WorkflowStepApprover {
        $assigneeId = $step->assignable ? $this->latestAssigneeId($transaction, $step, $fromCycle + 1) : null;

        if ($step->assignable && $assigneeId === null) {
            throw new WorkflowActionException('An approver is required for every assignable step.', 'assignees');
        }

        return $transaction->stepApprovers()->create([
            'workflow_step_id' => $step->getKey(),
            'approver_id' => $assigneeId,
            'cycle' => $cycle,
            'status' => $status,
        ]);
    }

    private function latestAssigneeId(Model $transaction, WorkflowStep $step, int $beforeCycle): int|string|null
    {
        $value = $transaction->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', '<', $beforeCycle)
            ->orderByDesc('cycle')
            ->value('approver_id');

        return $value === null ? null : (is_numeric($value) ? (int) $value : (string) $value);
    }

    private function writeLog(
        Model $transaction,
        WorkflowStep $step,
        Model $actor,
        string $action,
        int $cycle,
        ?WorkflowStepApprover $assignment = null,
        ?WorkflowStep $target = null,
        ?WorkflowStep $resume = null,
        ?string $remarks = null,
    ): WorkflowStepApproverLog {
        return $transaction->workflowLogs()->create([
            'workflow_step_approver_id' => $assignment?->getKey(),
            'workflow_step_id' => $step->getKey(),
            'approver_id' => $actor->getKey(),
            'action' => $action,
            'cycle' => $cycle,
            'reverted_to_step_id' => $target?->getKey(),
            'resume_step_id' => $resume?->getKey(),
            'remarks' => $remarks,
            'created_at' => now(),
        ]);
    }

    private function ruleAppliesTo(WorkflowStepRule $rule, Model $transaction): bool
    {
        $modelTypes = $rule->model_types;

        return $modelTypes === null || $modelTypes === [] || in_array($transaction->getMorphClass(), $modelTypes, true);
    }

    private function nextCycle(int $cycle): int
    {
        if ($cycle >= 65535) {
            throw new WorkflowActionException('The workflow has reached the maximum supported approval cycle.');
        }

        return $cycle + 1;
    }

    private function requireRemarks(string $remarks): void
    {
        if (trim($remarks) === '') {
            throw new WorkflowActionException('A reason is required for this workflow override.', 'remarks');
        }
    }

    private function dispatchAfterCommit(
        WorkflowEvent $event,
        ?string $notificationEvent = null,
        ?int $cycle = null,
        ?WorkflowStep $settingsStep = null,
    ): void {
        DB::connection()->afterCommit(function () use ($event, $notificationEvent, $cycle, $settingsStep): void {
            $event->workflowInstance->unsetRelation('workflow');
            $event->workflowInstance->unsetRelation('currentStep');
            $event->workflowInstance->unsetRelation('resumableStep');

            try {
                event($event);
            } catch (Throwable $exception) {
                report($exception);
            }

            if ($notificationEvent !== null && $cycle !== null) {
                try {
                    $this->notifier->notify($event, $notificationEvent, $cycle, $settingsStep);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }
}
