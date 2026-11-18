<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Services;

use Illuminate\Database\Eloquent\Model;

final class WorkflowAuthorizer
{
    public function isSuperAdministrator(Model $user): bool
    {
        $configured = config('workflow-pipelines.super_admin');

        if (is_callable($configured)) {
            return (bool) $configured($user);
        }

        if (is_string($configured) && class_exists($configured)) {
            $checker = app($configured);

            if (is_callable($checker)) {
                return (bool) $checker($user);
            }

            if (method_exists($checker, 'check')) {
                return (bool) $checker->check($user);
            }
        }

        $role = is_array($configured) ? ($configured['role'] ?? null) : null;

        return is_string($role) && method_exists($user, 'hasRole') && $user->hasRole($role);
    }

    public function canIndex(Model $user, string $permission): bool
    {
        return $this->isSuperAdministrator($user)
            || (method_exists($user, 'can') && $user->can($permission));
    }
}
