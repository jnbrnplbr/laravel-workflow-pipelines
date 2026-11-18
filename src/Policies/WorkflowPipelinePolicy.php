<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Policies;

use Illuminate\Database\Eloquent\Model;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;

final class WorkflowPipelinePolicy
{
    public function __construct(private readonly WorkflowAuthorizer $authorizer) {}

    public function viewAny(Model $user): bool
    {
        return $this->authorizer->canIndex($user, 'workflow-pipelines:index');
    }

    public function view(Model $user, WorkflowPipeline $pipeline): bool
    {
        return $this->authorizer->isSuperAdministrator($user)
            || (method_exists($user, 'can') && $user->can('workflow-pipelines:index'));
    }

    public function create(Model $user): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }

    public function update(Model $user, WorkflowPipeline $pipeline): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }

    public function delete(Model $user, WorkflowPipeline $pipeline): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }

    public function restore(Model $user, WorkflowPipeline $pipeline): bool
    {
        return $this->authorizer->isSuperAdministrator($user);
    }
}
