<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;

final class WorkflowDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workflow = $this->route('workflow');

        return $user instanceof Model
            && method_exists($user, 'can')
            && $workflow instanceof Workflow
            && $user->can('duplicate', $workflow);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique((new Workflow)->getTable(), 'name')],
        ];
    }
}
