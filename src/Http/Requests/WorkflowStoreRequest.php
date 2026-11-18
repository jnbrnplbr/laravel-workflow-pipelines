<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Jnbrnplbr\WorkflowPipelines\Http\Requests\Concerns\ValidatesWorkflowStepSettings;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;

class WorkflowStoreRequest extends FormRequest
{
    use ValidatesWorkflowStepSettings;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Model
            && method_exists($user, 'can')
            && $user->can('create', Workflow::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workflow = new Workflow;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique($workflow->getTable(), 'name')],
            'description' => ['nullable', 'string'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.client_key' => ['nullable', 'string', 'max:100'],
            'steps.*.sequence' => ['required', 'integer', 'min:1'],
            'steps.*.role_id' => ['required', 'integer', Rule::exists((string) config('workflow-pipelines.tables.roles'), 'id')],
            'steps.*.assignable' => ['required', 'boolean'],
            'steps.*.editable' => ['required', 'boolean'],
            'steps.*.status_id' => ['required', 'integer', Rule::exists((string) config('workflow-pipelines.tables.statuses'), 'id')],
            'steps.*.rules' => ['sometimes', 'array'],
            'steps.*.rules.*.action' => ['required', Rule::in(['approve', 'reject', 'revert'])],
            'steps.*.rules.*.label' => ['required', 'string', 'max:255'],
            'steps.*.rules.*.target_step_ids' => ['nullable', 'array'],
            'steps.*.rules.*.editable_fields' => ['nullable', 'array'],
            'steps.*.rules.*.editable_fields.*' => ['string'],
            'steps.*.rules.*.reset_approval' => ['sometimes', 'boolean'],
            'steps.*.rules.*.model_types' => ['nullable', 'array'],
            'steps.*.rules.*.model_types.*' => ['string'],
            'steps.*.notifications' => ['sometimes', 'array'],
            'steps.*.notifications.*.channel' => ['required', Rule::in(['database', 'mail'])],
            'steps.*.notifications.*.events' => ['required', 'array', 'min:1'],
            'steps.*.notifications.*.events.*' => ['string', Rule::in(['approved', 'reverted', 'rejected'])],
            'steps.*.notifications.*.recipients' => ['required', 'array'],
            'steps.*.notifications.*.recipients.*' => ['string', Rule::in(['next_handlers', 'creator', 'participants'])],
            'steps.*.notifications.*.emails' => ['nullable', 'array'],
            'steps.*.notifications.*.emails.*' => ['email'],
            'steps.*.notifications.*.subject' => ['nullable', 'string', 'max:255'],
            'steps.*.notifications.*.message' => ['nullable', 'string'],
            'steps.*.notifications.*.model_types' => ['nullable', 'array'],
            'steps.*.notifications.*.model_types.*' => ['string'],
            'steps.*.notifications.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
