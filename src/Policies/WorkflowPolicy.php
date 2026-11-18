<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Policies;

use Illuminate\Database\Eloquent\Model;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class WorkflowPolicy
{
    public function __construct(private readonly WorkflowAuthorizer $authorizer) {}

    public function viewAny(Model $user): bool
    {
        return $this->authorizer->canIndex($user, 'workflows:index');
    }

    public function view(Model $user, Workflow $workflow): bool
    {
        return $this->authorizer->isSuperAdministrator($user)
            || (method_exists($user, 'can') && $user->can('workflows:index'));
    }

    public function create(Model $user): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }

    public function update(Model $user, Workflow $workflow): bool
    {
        return $this->authorizer->isSuperAdministrator($user) && ! $workflow->isLocked();
    }

    public function delete(Model $user, Workflow $workflow): bool
    {
        return $this->authorizer->isSuperAdministrator($user)
            && ! $workflow->pipelines()->exists();
    }

    public function restore(Model $user, Workflow $workflow): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }

    public function duplicate(Model $user, Workflow $workflow): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }
}
