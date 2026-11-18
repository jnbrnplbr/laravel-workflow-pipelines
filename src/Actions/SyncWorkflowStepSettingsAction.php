<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Actions;

use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Exceptions\WorkflowActionException;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;

final class SyncWorkflowStepSettingsAction
{
    /** @param list<array<string, mixed>> $steps */
    public function execute(Workflow $workflow, array $steps): void
    {
        if ($steps === []) {
            throw new WorkflowActionException('A workflow must contain at least one step.', 'steps');
        }

        DB::transaction(function () use ($workflow, $steps): void {
            if ($workflow->isLocked()) {
                throw new WorkflowActionException('This workflow is in use and cannot be changed.', 'workflow');
            }

            $existing = $workflow->steps()->with(['rules', 'notifications'])->get()->keyBy(
                static fn (WorkflowStep $step): string => (string) $step->getKey(),
            );
            $maxSequence = (int) $existing->max('sequence');
            $temporaryBase = $maxSequence + count($steps) + $existing->count() + 100;
            $position = 0;

            foreach ($existing as $step) {
                $step->sequence = $temporaryBase + $position++;
                $step->save();
            }

            $sequenceOwners = [];
            $clientKeys = [];
            $stepMap = [];
            $savedSteps = [];
            $requestedIds = [];
            $seenIds = [];

            foreach ($steps as $index => $attributes) {
                $sequence = (int) ($attributes['sequence'] ?? ($index + 1));

                if ($sequence < 1 || isset($sequenceOwners[$sequence])) {
                    throw new WorkflowActionException('Workflow step positions must be unique positive numbers.', 'steps');
                }

                $sequenceOwners[$sequence] = true;
                $submittedId = isset($attributes['id']) ? (string) $attributes['id'] : null;
                $step = $submittedId !== null ? $existing->get($submittedId) : null;

                if ($submittedId !== null && $step === null) {
                    throw new WorkflowActionException('A submitted step does not belong to this workflow.', 'steps');
                }

                if ($step !== null) {
                    if (isset($seenIds[$submittedId])) {
                        throw new WorkflowActionException('A workflow step can appear only once in the submitted list.', 'steps');
                    }

                    $seenIds[$submittedId] = true;
                    $requestedIds[$submittedId] = true;
                } else {
                    $step = $workflow->steps()->create([
                        'role_id' => $attributes['role_id'],
                        'sequence' => $temporaryBase + $position++,
                        'assignable' => (bool) ($attributes['assignable'] ?? false),
                        'editable' => (bool) ($attributes['editable'] ?? false),
                        'status_id' => $attributes['status_id'],
                    ]);
                }

                $step->fill([
                    'role_id' => $attributes['role_id'],
                    'sequence' => $temporaryBase + $position++,
                    'assignable' => (bool) ($attributes['assignable'] ?? false),
                    'editable' => (bool) ($attributes['editable'] ?? false),
                    'status_id' => $attributes['status_id'],
                ])->save();

                $clientKey = (string) ($attributes['client_key'] ?? $submittedId ?? ('new-'.$index));

                if (isset($clientKeys[$clientKey]) && ! $clientKeys[$clientKey]->is($step)) {
                    throw new WorkflowActionException('Step client keys must be unique.', 'steps');
                }

                if (isset($stepMap[$clientKey]) && ! $stepMap[$clientKey]->is($step)) {
                    throw new WorkflowActionException('A step client key conflicts with another step id.', 'steps');
                }

                $clientKeys[$clientKey] = true;
                $stepMap[$clientKey] = $step;
                $stepMap[(string) $step->getKey()] = $step;
                $savedSteps[] = [$step, $attributes, $sequence];
            }

            if (count($sequenceOwners) !== count($steps)
                || array_keys($sequenceOwners) !== range(1, count($steps))) {
                $sequences = array_keys($sequenceOwners);
                sort($sequences);

                if ($sequences !== range(1, count($steps))) {
                    throw new WorkflowActionException('Workflow step positions must be consecutive, starting at 1.', 'steps');
                }
            }

            foreach ($existing as $id => $step) {
                if (! isset($requestedIds[$id])) {
                    $step->delete();
                }
            }

            foreach ($savedSteps as [$step, $attributes, $sequence]) {
                $step->sequence = $sequence;
                $step->save();
            }

            foreach ($savedSteps as [$step, $attributes, $sequence]) {
                if (array_key_exists('rules', $attributes)) {
                    $this->syncRules($step, $attributes['rules'] ?? [], $stepMap, $sequence);
                } else {
                    $this->validateExistingRules($step, $stepMap, $sequence);
                }

                $this->syncNotifications($step, $attributes['notifications'] ?? null);
            }
        });
    }

    /**
     * @param  array<string, WorkflowStep>  $stepMap
     * @param  list<array<string, mixed>>  $rules
     */
    private function syncRules(WorkflowStep $step, array $rules, array $stepMap, int $sequence): void
    {
        $step->rules()->delete();

        foreach ($rules as $attributes) {
            $targetIds = [];

            foreach ($attributes['target_step_ids'] ?? [] as $targetKey) {
                $target = $stepMap[(string) $targetKey] ?? null;

                if ($target === null || (int) $target->sequence >= $sequence) {
                    throw new WorkflowActionException('Revert rules can target earlier steps in the same workflow only.', 'steps');
                }

                $targetIds[] = (int) $target->getKey();
            }

            $step->rules()->create([
                'action' => $attributes['action'],
                'label' => $attributes['label'],
                'target_step_ids' => array_values(array_unique($targetIds)) ?: null,
                'editable_fields' => array_values(array_unique($attributes['editable_fields'] ?? [])) ?: null,
                'reset_approval' => (bool) ($attributes['reset_approval'] ?? false),
                'model_types' => array_values(array_unique($attributes['model_types'] ?? [])) ?: null,
            ]);
        }
    }

    /** @param array<string, WorkflowStep> $stepMap */
    private function validateExistingRules(WorkflowStep $step, array $stepMap, int $sequence): void
    {
        foreach ($step->rules as $rule) {
            foreach ($rule->target_step_ids ?? [] as $targetId) {
                $target = $stepMap[(string) $targetId] ?? null;

                if ($target === null || (int) $target->sequence >= $sequence) {
                    throw new WorkflowActionException('Existing revert rules must continue to target earlier steps in this workflow.', 'steps');
                }
            }
        }
    }

    /** @param list<array<string, mixed>>|null $notifications */
    private function syncNotifications(WorkflowStep $step, ?array $notifications): void
    {
        if ($notifications === null) {
            return;
        }

        $step->notifications()->delete();

        if ($notifications === []) {
            $step->notifications()->create([
                'channel' => 'database',
                'events' => ['approved', 'reverted', 'rejected'],
                'recipients' => ['next_handlers', 'creator'],
                'is_active' => true,
            ]);

            return;
        }

        foreach ($notifications as $attributes) {
            $step->notifications()->create([
                'channel' => $attributes['channel'],
                'events' => array_values(array_unique($attributes['events'] ?? [])),
                'recipients' => array_values(array_unique($attributes['recipients'] ?? [])),
                'emails' => array_values(array_unique($attributes['emails'] ?? [])) ?: null,
                'subject' => $attributes['subject'] ?? null,
                'message' => $attributes['message'] ?? null,
                'model_types' => array_values(array_unique($attributes['model_types'] ?? [])) ?: null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);
        }
    }
}
