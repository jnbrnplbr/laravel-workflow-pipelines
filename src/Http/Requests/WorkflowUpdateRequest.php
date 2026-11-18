<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;

final class WorkflowUpdateRequest extends WorkflowStoreRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workflow = $this->route('workflow');

        return $user instanceof Model
            && method_exists($user, 'can')
            && $workflow instanceof Workflow
            && $user->can('update', $workflow);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        $workflow = $this->route('workflow');
        $table = (new Workflow)->getTable();

        $rules['name'] = [
            'required',
            'string',
            'max:255',
            Rule::unique($table, 'name')->ignore($workflow instanceof Workflow ? $workflow->getKey() : null),
        ];

        return $rules;
    }
}
