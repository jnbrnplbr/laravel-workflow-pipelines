<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Actions;

use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;

final class DuplicateWorkflowAction
{
    public function execute(Workflow $source, string $name): Workflow
    {
        return DB::transaction(function () use ($source, $name): Workflow {
            $source->load(['steps.rules', 'steps.notifications']);
            $copy = Workflow::query()->create([
                'name' => $name,
                'description' => $source->description,
            ]);
            $stepMap = [];
            $newSteps = [];

            foreach ($source->steps as $sourceStep) {
                $step = $copy->steps()->create([
                    'role_id' => $sourceStep->role_id,
                    'sequence' => $sourceStep->sequence,
                    'assignable' => $sourceStep->assignable,
                    'editable' => $sourceStep->editable,
                    'status_id' => $sourceStep->status_id,
                ]);
                $stepMap[(string) $sourceStep->getKey()] = (int) $step->getKey();
                $newSteps[(string) $sourceStep->getKey()] = $step;

                $step->notifications()->delete();

                foreach ($sourceStep->notifications as $notification) {
                    $step->notifications()->create([
                        'channel' => $notification->channel,
                        'events' => $notification->events,
                        'recipients' => $notification->recipients,
                        'emails' => $notification->emails,
                        'subject' => $notification->subject,
                        'message' => $notification->message,
                        'model_types' => $notification->model_types,
                        'is_active' => $notification->is_active,
                    ]);
                }
            }

            foreach ($source->steps as $sourceStep) {
                /** @var WorkflowStep $targetStep */
                $targetStep = $newSteps[(string) $sourceStep->getKey()];

                foreach ($sourceStep->rules as $rule) {
                    $targets = array_values(array_filter(array_map(
                        static fn (int|string $id): ?int => $stepMap[(string) $id] ?? null,
                        $rule->target_step_ids ?? [],
                    )));

                    $targetStep->rules()->create([
                        'action' => $rule->action,
                        'label' => $rule->label,
                        'target_step_ids' => $targets === [] ? null : $targets,
                        'editable_fields' => $rule->editable_fields,
                        'reset_approval' => $rule->reset_approval,
                        'model_types' => $rule->model_types,
                    ]);
                }
            }

            return $copy->load(['steps.rules', 'steps.notifications']);
        });
    }
}
