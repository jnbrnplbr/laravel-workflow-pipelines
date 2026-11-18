<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;

final class WorkflowPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $pipeline = $this->route('pipeline');

        if (! $user instanceof Model) {
            return false;
        }

        return $pipeline instanceof WorkflowPipeline
            ? method_exists($user, 'can') && $user->can('update', $pipeline)
            : method_exists($user, 'can') && $user->can('create', WorkflowPipeline::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $aliases = $this->registeredAliases();

        return [
            'model_type' => ['required', 'string', Rule::in($aliases)],
            'unit_id' => ['nullable', 'integer', Rule::exists((string) config('workflow-pipelines.tables.organizations'), 'id')],
            'workflow_id' => [
                'required',
                'integer',
                Rule::exists((new Workflow)->getTable(), 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('model_type');
            $unitId = $this->input('unit_id');

            if (! is_string($type) || ! in_array($type, $this->registeredAliases(), true)) {
                return;
            }

            $query = WorkflowPipeline::withTrashed()->where('model_type', $type);
            $unitId === null ? $query->whereNull('unit_id') : $query->where('unit_id', $unitId);
            $pipeline = $this->route('pipeline');

            if ($pipeline instanceof WorkflowPipeline) {
                $query->where($query->getModel()->getKeyName(), '<>', $pipeline->getKey());
            }

            if ($query->exists()) {
                $validator->errors()->add('unit_id', 'A pipeline already exists for this transaction type and organization.');
            }
        });
    }

    /** @return list<string> */
    private function registeredAliases(): array
    {
        $aliases = [];

        foreach (app(ApprovableModelRegistry::class)->all() as $modelClass) {
            $aliases[] = (new $modelClass)->getMorphClass();
        }

        return array_values(array_unique($aliases));
    }
}
