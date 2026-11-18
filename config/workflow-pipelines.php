<?php

declare(strict_types=1);
use App\Models\User;
use Spatie\Permission\Models\Role;

return [
    'table_prefix' => env('WORKFLOW_PIPELINES_TABLE_PREFIX', ''),

    'models' => [
        'user' => env('WORKFLOW_PIPELINES_USER_MODEL', User::class),
        'role' => env('WORKFLOW_PIPELINES_ROLE_MODEL', Role::class),
        'organization' => env('WORKFLOW_PIPELINES_ORGANIZATION_MODEL'),
        'status' => env('WORKFLOW_PIPELINES_STATUS_MODEL'),
    ],

    'tables' => [
        'users' => env('WORKFLOW_PIPELINES_USERS_TABLE', 'users'),
        'roles' => env('WORKFLOW_PIPELINES_ROLES_TABLE', 'roles'),
        'organizations' => env('WORKFLOW_PIPELINES_ORGANIZATIONS_TABLE', 'organizations'),
        'statuses' => env('WORKFLOW_PIPELINES_STATUSES_TABLE', 'statuses'),
    ],

    'organization' => [
        'membership_relation' => env('WORKFLOW_PIPELINES_ORGANIZATION_RELATION', 'organizations'),
    ],

    'super_admin' => [
        'role' => env('WORKFLOW_PIPELINES_SUPER_ADMIN_ROLE', 'Super Administrator'),
    ],

    'permissions' => [
        'approve' => [],
    ],

    'routes' => [
        'enabled' => env('WORKFLOW_PIPELINES_ROUTES_ENABLED', true),
        'prefix' => env('WORKFLOW_PIPELINES_ROUTES_PREFIX', 'development/pipelines'),
        'name' => env('WORKFLOW_PIPELINES_ROUTES_NAME', 'development.pipelines.'),
        'middleware' => ['web', 'auth'],
    ],

    'notifications' => [
        'enabled' => env('WORKFLOW_PIPELINES_NOTIFICATIONS_ENABLED', true),
        'queue' => env('WORKFLOW_PIPELINES_NOTIFICATIONS_QUEUE', false),
    ],

    'inertia' => [
        'pages_path' => env('WORKFLOW_PIPELINES_INERTIA_PAGES_PATH', 'Vendor/WorkflowPipelines'),
    ],

    'approvables' => [],
];
