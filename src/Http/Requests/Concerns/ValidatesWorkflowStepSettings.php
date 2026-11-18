<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;

trait ValidatesWorkflowStepSettings
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $steps = $this->input('steps', []);

            if (! is_array($steps) || $steps === []) {
                return;
            }

            $knownAliases = $this->registeredModelAliases();
            $fieldsByAlias = $this->editableFieldsByAlias();
            $clientKeys = [];
            $sequences = [];
            $submittedIds = [];

            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $sequence = filter_var($step['sequence'] ?? null, FILTER_VALIDATE_INT);
                if ($sequence !== false && $sequence !== null) {
                    if (isset($sequences[$sequence])) {
                        $validator->errors()->add("steps.$index.sequence", 'Step positions must be unique.');
                    }

                    $sequences[$sequence] = true;
                }

                $key = (string) ($step['client_key'] ?? $step['id'] ?? ('new-'.$index));

                if (isset($clientKeys[$key]) && $clientKeys[$key]['id'] !== ($step['id'] ?? null)) {
                    $validator->errors()->add("steps.$index.client_key", 'Step client keys must be unique.');
                }

                $clientKeys[$key] = ['sequence' => $sequence, 'id' => isset($step['id']) ? (string) $step['id'] : null];
                if (isset($step['id'])) {
                    if (isset($submittedIds[(string) $step['id']])) {
                        $validator->errors()->add("steps.$index.id", 'A workflow step can appear only once.');
                    }

                    $submittedIds[(string) $step['id']] = true;

                    if (isset($clientKeys[(string) $step['id']]) && (string) $step['id'] !== $key) {
                        $validator->errors()->add("steps.$index.id", 'A step id cannot duplicate another client key.');
                    }
                    $clientKeys[(string) $step['id']] = $clientKeys[$key];
                }
            }

            $sortedSequences = array_keys($sequences);
            sort($sortedSequences);

            if ($sortedSequences !== range(1, count($steps))) {
                $validator->errors()->add('steps', 'Step positions must be consecutive, starting at 1.');
            }

            foreach ($steps as $stepIndex => $step) {
                if (! is_array($step)) {
                    continue;
                }

                foreach (($step['rules'] ?? []) as $ruleIndex => $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }

                    $aliases = $this->selectedAliases($rule['model_types'] ?? null, $knownAliases);
                    $allowedFields = $this->fieldsForAliases($aliases, $fieldsByAlias);

                    foreach (($rule['model_types'] ?? []) as $modelIndex => $alias) {
                        if (! in_array($alias, $knownAliases, true)) {
                            $validator->errors()->add("steps.$stepIndex.rules.$ruleIndex.model_types.$modelIndex", 'Select a registered transaction type.');
                        }
                    }

                    foreach (($rule['editable_fields'] ?? []) as $fieldIndex => $field) {
                        if (is_string($field) && ! in_array($field, $allowedFields, true)) {
                            $validator->errors()->add("steps.$stepIndex.rules.$ruleIndex.editable_fields.$fieldIndex", 'This field is not registered for the selected transaction types.');
                        }
                    }

                    if (($rule['action'] ?? null) !== 'revert') {
                        continue;
                    }

                    $targets = $rule['target_step_ids'] ?? [];

                    if (! is_array($targets) || $targets === []) {
                        $validator->errors()->add("steps.$stepIndex.rules.$ruleIndex.target_step_ids", 'Select at least one earlier step as a revert target.');

                        continue;
                    }

                    $sourceSequence = filter_var($step['sequence'] ?? null, FILTER_VALIDATE_INT);

                    foreach ($targets as $targetIndex => $target) {
                        if (! is_int($target) && ! is_string($target)) {
                            $validator->errors()->add("steps.$stepIndex.rules.$ruleIndex.target_step_ids.$targetIndex", 'A revert target must be a step id or client key.');

                            continue;
                        }

                        $targetInfo = $clientKeys[(string) $target] ?? null;

                        if ($targetInfo === null || $sourceSequence === false || $targetInfo['sequence'] === false || $targetInfo['sequence'] >= $sourceSequence) {
                            $validator->errors()->add("steps.$stepIndex.rules.$ruleIndex.target_step_ids.$targetIndex", 'A revert target must be an earlier step in this workflow.');
                        }
                    }
                }

                foreach (($step['notifications'] ?? []) as $notificationIndex => $notification) {
                    if (! is_array($notification)) {
                        continue;
                    }

                    $aliases = $this->selectedAliases($notification['model_types'] ?? null, $knownAliases);
                    $allowedFields = $this->fieldsForAliases($aliases, $fieldsByAlias);

                    foreach (($notification['model_types'] ?? []) as $modelIndex => $alias) {
                        if (! in_array($alias, $knownAliases, true)) {
                            $validator->errors()->add("steps.$stepIndex.notifications.$notificationIndex.model_types.$modelIndex", 'Select a registered transaction type.');
                        }
                    }

                }
            }
        });
    }

    /** @return list<string> */
    protected function registeredModelAliases(): array
    {
        $aliases = [];

        foreach (app(ApprovableModelRegistry::class)->all() as $modelClass) {
            $model = new $modelClass;
            $aliases[] = $model->getMorphClass();
        }

        return array_values(array_unique($aliases));
    }

    /** @return array<string, list<string>> */
    protected function editableFieldsByAlias(): array
    {
        $fields = [];

        foreach (app(ApprovableModelRegistry::class)->all() as $modelClass) {
            $model = new $modelClass;
            $alias = $model->getMorphClass();
            $fields[$alias] = array_keys($modelClass::workflowEditableFields());
        }

        return $fields;
    }

    /** @param list<string> $knownAliases @return list<string> */
    private function selectedAliases(mixed $selected, array $knownAliases): array
    {
        if (! is_array($selected) || $selected === []) {
            return $knownAliases;
        }

        return array_values(array_filter($selected, static fn (mixed $alias): bool => is_string($alias) && in_array($alias, $knownAliases, true)));
    }

    /** @param list<string> $aliases @param array<string, list<string>> $fieldsByAlias @return list<string> */
    private function fieldsForAliases(array $aliases, array $fieldsByAlias): array
    {
        $fields = [];

        foreach ($aliases as $alias) {
            array_push($fields, ...($fieldsByAlias[$alias] ?? []));
        }

        return array_values(array_unique($fields));
    }
}
