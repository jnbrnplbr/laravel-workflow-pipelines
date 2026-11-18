<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class ProcessApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $transaction = $this->route('transaction');

        if (! $user instanceof Model || ! $transaction instanceof Model || ! $transaction instanceof Approvable) {
            return false;
        }

        $instance = $transaction->transactionWorkflow()->first();

        if ($instance === null || ! $instance->canBeActedOnBy($user)) {
            return false;
        }

        $permission = config('workflow-pipelines.permissions.approve.'.$transaction->getMorphClass());

        return ! is_string($permission)
            || app(WorkflowAuthorizer::class)->isSuperAdministrator($user)
            || (method_exists($user, 'can') && $user->can($permission));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'revert', 'reject'])],
            'remarks' => ['required_if:action,reject', 'nullable', 'string', 'max:10000'],
            'rule_id' => ['nullable', 'integer'],
            'target_step_id' => ['required_if:action,revert', 'nullable', 'integer'],
            'unlocked_fields' => ['sometimes', 'array'],
            'unlocked_fields.*' => ['string'],
        ];
    }
}
