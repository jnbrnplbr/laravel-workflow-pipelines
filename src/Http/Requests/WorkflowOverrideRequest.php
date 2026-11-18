<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class WorkflowOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Model && app(WorkflowAuthorizer::class)->isSuperAdministrator($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['reopen', 'move', 'reroute'])],
            'remarks' => ['required_if:action,reopen,move', 'nullable', 'string', 'max:10000'],
            'target_step_id' => ['required_if:action,move,reroute', 'nullable', 'integer'],
            'workflow_id' => ['required_if:action,reroute', 'nullable', 'integer', Rule::exists((new Workflow)->getTable(), 'id')->whereNull('deleted_at')],
            'unlocked_fields' => ['sometimes', 'array'],
            'unlocked_fields.*' => ['string'],
            'assignees' => ['required_if:action,reroute', 'array'],
            'assignees.*' => ['required', 'integer', Rule::exists((string) config('workflow-pipelines.tables.users'), 'id')],
        ];
    }
}
