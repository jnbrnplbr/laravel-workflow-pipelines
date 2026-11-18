# Laravel Workflow Pipelines

**Composer library:** `jnbrnplbr/laravel-workflow-pipelines`  
**PHP namespace:** `Jnbrnplbr\WorkflowPipelines`

Laravel Workflow Pipelines adds configurable, reusable approval flows to Eloquent transactions. A transaction keeps the workflow snapshot it started with, even when administrators later change the workflow template.

## Requirements

- PHP 8.3+
- Laravel 13
- Inertia Laravel 3 and a Vue 3 host frontend
- Spatie Laravel Permission 8
- Host models/tables for users, roles, organizations and display statuses

The Composer package is installed by Laravel package discovery. No package-owned frontend build, UI kit, or HTTP client is required; the published Vue files use the host's `AppLayout`, `ActionButton`, UI tokens, and Wayfinder.

## Install in a host application

```sh
composer require jnbrnplbr/laravel-workflow-pipelines
php artisan workflow-pipelines:install
php artisan migrate
php artisan wayfinder:generate --path=resources/js/wayfinder
```

The installer publishes configuration, timestamped migrations, translations, Vue pages/components, and the Wayfinder adapter. Existing published files are kept. `--force` replaces existing config, frontend, and translation files when you explicitly want to discard local edits. It never replaces a migration. To publish only migration files that are not already present, use `php artisan workflow-pipelines:install --migrations`.

Set `WORKFLOW_PIPELINES_USER_MODEL`, `WORKFLOW_PIPELINES_ROLE_MODEL`, `WORKFLOW_PIPELINES_ORGANIZATION_MODEL`, and `WORKFLOW_PIPELINES_STATUS_MODEL` as needed, or edit `config/workflow-pipelines.php`. Set the corresponding table names under `tables`, the organization membership relation, and `approvables`. Register stable morph aliases for every transaction type before writing workflow records. `inertia.pages_path` defaults to `Vendor/WorkflowPipelines`; the installed pages must match that path in the host's Inertia resolver.

For example, a seed/config setup for the model above can use:

```php
// config/workflow-pipelines.php
'models' => [
    'user' => App\Models\User::class,
    'role' => Spatie\Permission\Models\Role::class,
    'organization' => App\Models\Organization::class,
    'status' => App\Models\DisplayStatus::class,
],
'approvables' => [App\Models\PurchaseRequest::class],
```

Create role and display-status records in the host's own tables first. Then seed a workflow and its global mapping; step `role_id` and `status_id` must reference those host records:

```php
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use App\Models\PurchaseRequest;

$workflow = Workflow::create(['name' => 'Purchase review']);
$workflow->steps()->create(['role_id' => $reviewerRoleId, 'sequence' => 1, 'assignable' => false, 'editable' => false, 'status_id' => $submittedStatusId]);
$workflow->steps()->create(['role_id' => $directorRoleId, 'sequence' => 2, 'assignable' => false, 'editable' => false, 'status_id' => $approvedStatusId]);
WorkflowPipeline::create(['model_type' => (new PurchaseRequest)->getMorphClass(), 'unit_id' => null, 'workflow_id' => $workflow->getKey()]);
```

The host must provide `@/layouts/AppLayout.vue`, a global `ActionButton`, and Tailwind 4 theme tokens. The included route adapter imports generated helpers from `@/wayfinder/routes/development/pipelines/...`; regenerate Wayfinder after installing or changing package routes.

## Sample application integration

This example uses a `PurchaseRequest` transaction with `unit_id`, `created_by`, `title`, and soft deletes. Adapt field names and authorization to the host application.

```php
// app/Models/PurchaseRequest.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jnbrnplbr\WorkflowPipelines\Concerns\HasWorkflowApprovals;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;

class PurchaseRequest extends Model implements Approvable
{
    use HasWorkflowApprovals;
    use SoftDeletes;

    protected $fillable = ['unit_id', 'created_by', 'title', 'description'];

    public static function workflowEditableFields(): array
    {
        return ['title' => 'Title', 'description' => 'Description'];
    }

    public static function workflowModelLabel(): string { return 'Purchase Request'; }
    public function workflowUrl(): ?string { return route('purchase-requests.show', $this); }
}
```

Add the model to `approvables`, then register morph aliases before any transaction/workflow morph rows are created. Include host package models in the application's enforced map as required by its morph-map policy:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\PurchaseRequest;
use Jnbrnplbr\WorkflowPipelines\Models\{TransactionWorkflow, Workflow, WorkflowPipeline, WorkflowStep, WorkflowStepApprover, WorkflowStepApproverLog, WorkflowStepNotification, WorkflowStepRule};

Relation::enforceMorphMap([
    'purchase-request' => PurchaseRequest::class,
    'workflow' => Workflow::class,
    'workflow-step' => WorkflowStep::class,
    'workflow-step-rule' => WorkflowStepRule::class,
    'workflow-step-notification' => WorkflowStepNotification::class,
    'workflow-pipeline' => WorkflowPipeline::class,
    'transaction-workflow' => TransactionWorkflow::class,
    'workflow-step-approver' => WorkflowStepApprover::class,
    'workflow-step-approver-log' => WorkflowStepApproverLog::class,
]);
```

Create at least one workflow with steps and a global pipeline (`unit_id = null`) for each transaction type. Unit-specific pipelines take precedence over that global default. Once configured, resolve and start the workflow inside the host's transaction creation action:

```php
use Illuminate\Support\Facades\DB;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowEngine;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowResolver;

$purchaseRequest = DB::transaction(function () use ($request, $user) {
    $purchaseRequest = PurchaseRequest::create([
        ...$request->validated(),
        'created_by' => $user->getKey(),
    ]);

    $workflow = app(WorkflowResolver::class)->resolve($purchaseRequest);
    abort_if($workflow === null, 422, 'No workflow is configured for this transaction.');

    // Only assignable steps need entries: [workflow_step_id => user_id].
    app(WorkflowEngine::class)->start($purchaseRequest, $workflow, [], $user);

    return $purchaseRequest;
});
```

For an approval endpoint, authorize the current handler and call `WorkflowEngine::approve($transaction, $actor)`. Use `ProcessApprovalRequest` for the package's action payload; route-model bind the transaction as `{transaction}`. Dispatch `revert($transaction, $actor, $targetStepId, $ruleId, $unlockedFields, $remarks)` or `reject($transaction, $actor, $remarks)` for the other handler actions. Host controllers must continue to enforce transaction-specific policies.

```php
// app/Http/Controllers/PurchaseRequestApprovalController.php
public function __invoke(ProcessApprovalRequest $request, PurchaseRequest $transaction, WorkflowEngine $engine)
{
    $data = $request->validated();
    $actor = $request->user();

    match ($data['action']) {
        'approve' => $engine->approve($transaction, $actor),
        'revert' => $engine->revert(
            $transaction,
            $actor,
            (int) $data['target_step_id'],
            isset($data['rule_id']) ? (int) $data['rule_id'] : null,
            $data['unlocked_fields'] ?? [],
            $data['remarks'] ?? null,
        ),
        'reject' => $engine->reject($transaction, $actor, $data['remarks']),
    };

    return back();
}
```

Mount the published `WorkflowActionBanner`, `WorkflowOverridePanel`, `WorkflowProgressTracker`, and `WorkflowAuditTrail` on the host transaction show page. The first two emit an action and data payload; wire those events to host-authorized endpoints. The progress panel expects steps for the current cycle, and the audit panel expects actor names to be loaded by the host. Components do not bypass server-side authorization.

Run `php artisan workflow-pipelines:doctor` after configuring models, morph aliases, migrations, and global pipelines. It reports missing setup, empty workflows, and duplicate assignment keys.

## Development

```sh
composer test
composer analyse
composer format:check
composer check
```

Tests use Pest and Orchestra Testbench. Run the suite on both MySQL and SQLite in a configured development environment. See [CHANGELOG.md](CHANGELOG.md) and [UPGRADE.md](UPGRADE.md) for release notes and schema update policy.
