# Prompt: Build the Workflow Pipelines Package (Laravel)

| Field               | Value                                                                                                                                                                                                                      |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document purpose    | A complete, self-contained prompt for an AI coding agent (or a developer) to build the workflow pipeline module as a reusable Laravel package that any application with the same stack can install, configure, and update. |
| Source              | Extracted from the PAMIS implementation and decisions of 2026-11-16 and 2026-11-17 ([modules/workflow-pipelines.md](modules/workflow-pipelines.md), [modules/prompt.md](modules/prompt.md))                                |
| Last analyzed       | 2026-11-17                                                                                                                                                                                                                 |
| Verification status | Rules and schema checked against the PAMIS code of 2026-11-17 (PHPStan 0 errors; 88 workflow, pipeline, and PPMP feature tests passing). The package itself is not built yet.                                              |
| Related             | [modules/workflow-pipelines.md](modules/workflow-pipelines.md), [development-guide.md](development-guide.md)                                                                                                               |

## How to use this file

1. Replace the placeholders in section 0 (vendor, package, and namespace names).
2. Give everything from "PROMPT START" to "PROMPT END" to the agent, in a new, empty repository for the package.
3. Build in the phases of section 13. Review each phase before the next.
4. After the package is tagged, install it in PAMIS (section 12.3) and remove the in-app copy in one change.

---

<!-- PROMPT START -->

## 0. Names (fill in before use)

| Placeholder   | Example                      | Used for                       |
| ------------- | ---------------------------- | ------------------------------ |
| `{vendor}`    | `jnbrnplbr`                  | Composer vendor and npm scope  |
| `{package}`   | `laravel-workflow-pipelines` | Composer and npm package name  |
| `{Namespace}` | `Jnbrnplbr\WorkflowPipelines` | PHP root namespace             |
| `{prefix}`    | `wf_` or empty               | Optional table prefix (config) |

## 1. Role and goal

You are a senior Laravel package author. Build **`{vendor}/{package}`**: a reusable, versioned Laravel package that adds configurable approval workflows to any Eloquent model ("transaction"), for example a procurement plan, a purchase request, or an enrollment.

Business goal: when approval requirements change, an administrator changes the process in the UI (steps, roles, rules, notifications, which workflow applies to which transaction type and organization) without code changes, and **transactions already in progress are never affected**.

The host application installs the package with Composer, publishes its config, migrations, and Vue pages, adds one trait and one interface to each transaction model, and calls one engine from its own create and approve actions. Updating the package later must not overwrite the host's data or its customized files.

## 2. Target stack (the host applications)

| Layer                 | Version                                                | Notes                                                                                                                               |
| --------------------- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| PHP                   | 8.3+                                                   | Typed properties, enums, readonly, intersection types                                                                               |
| Laravel               | 13                                                     | Package discovery, `HasMiddleware` controllers                                                                                      |
| Inertia               | v3 (`inertiajs/inertia-laravel`, `@inertiajs/vue3`)    | Server-side rendering may be enabled in the host                                                                                    |
| Vue                   | 3, `<script setup lang="ts">`                          |                                                                                                                                     |
| Tailwind              | v4                                                     | Theme tokens from the host (`bg-card`, `text-muted-foreground`, `bg-primary`, ...); never raw palette colors without a `dark:` pair |
| UI primitives         | shadcn-vue / reka-ui in the host (`@/components/ui/*`) | The package must not ship its own UI kit                                                                                            |
| Routes in TS          | Laravel Wayfinder in the host                          | Pages build URLs through generated helpers, never hard-coded paths                                                                  |
| Roles and permissions | `spatie/laravel-permission`                            | Steps reference a role                                                                                                              |
| Tests                 | Pest 4, Orchestra Testbench, Larastan level 5          |                                                                                                                                     |

Do not add other runtime dependencies without listing why. No state library, no HTTP client, no UI kit.

## 3. Package deliverables and layout

```text
{package}/
├── composer.json            # autoload {Namespace}\, extra.laravel.providers, require laravel/framework ^13, spatie/laravel-permission
├── config/workflow-pipelines.php
├── database/migrations/      # publishable, timestamp-free stubs (see 4.9)
├── routes/web.php            # admin routes, loaded only if config('workflow-pipelines.routes.enabled')
├── resources/js/             # publishable Vue pages, components, TS helpers (tag: workflow-pipelines-views)
├── resources/lang/en/        # all user-facing strings
├── src/
│   ├── WorkflowPipelinesServiceProvider.php
│   ├── Contracts/Approvable.php
│   ├── Contracts/OrganizationResolver.php
│   ├── Concerns/HasWorkflowApprovals.php
│   ├── Models/{Workflow, WorkflowStep, WorkflowStepRule, WorkflowStepNotification, WorkflowPipeline, TransactionWorkflow, WorkflowStepApprover, WorkflowStepApproverLog}.php
│   ├── Concerns/AppliesToModelTypes.php
│   ├── Services/{WorkflowEngine, WorkflowNotifier, WorkflowResolver, ApprovableModelRegistry}.php
│   ├── Notifications/WorkflowStepActionNotification.php
│   ├── Exceptions/WorkflowActionException.php
│   ├── Http/Controllers/{WorkflowController, WorkflowPipelineController}.php
│   ├── Http/Requests/{WorkflowStoreRequest, WorkflowUpdateRequest, WorkflowPipelineRequest, ProcessApprovalRequest, WorkflowOverrideRequest}.php
│   ├── Http/Requests/Concerns/ValidatesWorkflowStepSettings.php
│   ├── Actions/{DuplicateWorkflowAction, SyncWorkflowStepSettingsAction}.php
│   ├── Policies/{WorkflowPolicy, WorkflowPipelinePolicy}.php
│   ├── Events/{WorkflowStarted, StepApproved, StepReverted, StepRejected, WorkflowCompleted, WorkflowReopened, WorkflowMoved, WorkflowRerouted, ApproverReassigned}.php
│   └── Console/{InstallCommand, DoctorCommand}.php
├── tests/                    # Pest + Testbench; a fixture "Ppmp-like" transaction model
├── CHANGELOG.md, UPGRADE.md, README.md
└── phpstan.neon (level 5, 0 errors), pint.json (laravel preset)
```

The service provider registers config, migrations (publish tag `workflow-pipelines-migrations`), views and Vue stubs (tag `workflow-pipelines-views`), translations, policies, routes (optional), the engine as a singleton, and the console commands.

## 4. Data model (create these tables)

All tables honor `config('workflow-pipelines.table_prefix')`. Foreign keys to host tables (`users`, `roles`, organizations, display statuses) use the table names from config. Morph columns store the morph alias; tell the host to register a morph map (section 12.2).

### 4.1 `workflows`

`id`, `name` (unique), `description` (nullable text), timestamps, soft deletes. A workflow is a reusable template.

### 4.2 `workflow_steps`

`id`, `workflow_id` (FK cascade), `role_id` (FK to the permission roles table), `sequence` (unsigned int; unique with `workflow_id`), `assignable` (bool), `editable` (bool, default false), `status_id` (FK to the host's display-status table, restrict), timestamps. No JSON `revert_rules` column (rules live in 4.3).

### 4.3 `workflow_step_rules`

`id`, `workflow_step_id` (FK cascade), `action` (string 20: `approve` | `reject` | `revert`), `label` (string), `target_step_ids` (JSON nullable, revert only), `editable_fields` (JSON nullable, revert only), `reset_approval` (bool default false, revert only), `model_types` (JSON nullable; NULL or empty means every transaction type), timestamps. Index (`workflow_step_id`, `action`).

### 4.4 `workflow_step_notifications`

`id`, `workflow_step_id` (FK cascade), `channel` (string 20: `database` | `mail`), `events` (JSON subset of `approved`, `reverted`, `rejected`), `recipients` (JSON subset of `next_handlers`, `creator`, `participants`), `emails` (JSON nullable, mail only), `subject` (nullable), `message` (nullable text), `model_types` (JSON nullable), `is_active` (bool default true), timestamps. Index (`workflow_step_id`, `is_active`).

Every step gets a **default notification** when created: channel `database`, all three events, recipients `next_handlers` and `creator`. A step without rows behaves as if it had that default (unsaved), so no backfill is ever needed.

### 4.5 `workflow_pipelines`

`id`, `model_type` (morph alias of the transaction type), `unit_id` (nullable FK to the host's organization table, cascade), `workflow_id` (FK), soft deletes, timestamps. `unit_id` NULL is the global default for that transaction type; a unit row overrides it for that unit. Add a unique key on (`model_type`, `unit_id`) where the database allows it, otherwise enforce in validation.

### 4.6 `transaction_workflows` (one per transaction)

`id`, approvable morph (unique), `workflow_id` (the snapshot the transaction runs on), `current_step_id` (nullable FK), `resumable_step_id` (nullable FK), `unlocked_fields` (JSON nullable), `reverted_by_rule_id` (nullable FK to rules, null on delete), `status_id` (display status, restrict), `status` (string 30: `draft` | `pending` | `approved` | `rejected`), `cycle` (unsigned small int, default 1), `submitted_at`, `submitted_by` (nullable FK users), `reverted_at`, `rejected_at`, `completed_at`, `cancelled_at`, `last_action_at`, `last_actor_id` (nullable FK users), timestamps, soft deletes. Index (`approvable_type`, `status_id`, `status`).

### 4.7 `workflow_step_approvers` (assignments)

`id`, approvable morph, `workflow_step_id` (FK cascade), `approver_id` (nullable FK users, null on delete; **the assigned user only**, NULL for role-pool steps), `cycle`, `status` (string 30: `pending` | `approved` | `reverted` | `rejected`), timestamps. **Unique** (`approvable_type`, `approvable_id`, `workflow_step_id`, `cycle`). Index (`approver_id`, `status`, ...). No remarks or timestamps here: the log is the history.

### 4.8 `workflow_step_approver_logs` (append-only history)

`id`, approvable morph, `workflow_step_approver_id` (nullable FK, null on delete), `workflow_step_id` (FK restrict), `approver_id` (FK users restrict; the **actor**), `action` (string 50), `cycle`, `reverted_to_step_id` (nullable; target step of revert, move, reopen, re-route), `resume_step_id` (nullable), `remarks` (text nullable), `created_at`. Index (approvable, `created_at`). Log actions: `created`, `submitted` (step-1 approval), `approved`, `reverted`, `rejected`, `reopened`, `override` (approval filled by a forward move), `moved`, `reassigned`, `rerouted`.

### 4.9 Migration rules

- Ship one migration per table as publishable stubs. The install command publishes them with the current timestamp.
- A later package version that changes the schema ships a **new additive migration**; never edit a published one. Document it in `UPGRADE.md`.
- A migration that would fail on existing data (for example a new unique key) must check first and stop with a clear list of offending rows. It must never change data silently.

## 5. Host integration contract

### 5.1 Transaction models

A transaction model implements `{Namespace}\Contracts\Approvable` and uses `{Namespace}\Concerns\HasWorkflowApprovals`, which provides every interface method:

```php
interface Approvable
{
    /** @return array<string, string> key => label of fields and sections a revert may unlock */
    public static function workflowEditableFields(): array;
    public static function workflowModelLabel(): string;    // "PPMP"
    public static function workflowUnitColumn(): string;    // default 'unit_id'
    public function workflowUnitId(): ?int;
    public function workflowCreatorId(): ?int;             // default created_by
    public function workflowTitle(): string;               // for notifications
    public function workflowUrl(): ?string;                // show page link
    public function transactionWorkflow(): MorphOne;
}
```

The trait also provides: relations `transactionWorkflow`, `stepApprovers`, `workflowLogs`; `getWorkflowInstance()`; state helpers (`isDraft`, `isPending`, `isApproved`, `isRejected`, `isReverted`); `workflowEditScope(User)`, `canEditWorkflowField(User, key)`, `canEditAnyWorkflowField(User)`; scopes `pendingApprovalFor(User)` (one SQL query, usable for sidebar badges), `approved()`, `draft()`; and a boot hook that soft-deletes and restores the `transaction_workflows` row with the transaction.

### 5.2 Configuration (`config/workflow-pipelines.php`)

| Key                                                                       | Purpose                                                                                                                |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `table_prefix`                                                            | Optional prefix for package tables                                                                                     |
| `models.user`, `models.role`, `models.organization`, `models.status`      | Host model classes                                                                                                     |
| `tables.users`, `tables.roles`, `tables.organizations`, `tables.statuses` | Host table names for foreign keys                                                                                      |
| `organization.membership_relation`                                        | Relation on the user model listing its organizations (for example `organizations`)                                     |
| `super_admin`                                                             | Callable or class `fn (User $u): bool`; default checks a role name from config                                         |
| `permissions.approve`                                                     | Map of morph alias → permission name a handler also needs (for example `ppmp:approve`); empty means none               |
| `routes.enabled`, `routes.prefix`, `routes.name`, `routes.middleware`     | Admin UI routes (default `development/pipelines`, `development.pipelines.`, `['web', 'auth']`)                         |
| `notifications.enabled`, `notifications.queue`                            | Turn sending off, or queue it                                                                                          |
| `inertia.pages_path`                                                      | Where published pages live (default `Vendor/WorkflowPipelines`)                                                        |
| `approvables`                                                             | Optional explicit list of transaction model classes; when empty, discover models under `app/Models` that use the trait |

### 5.3 What the host calls

- On create: resolve the workflow on the server with `WorkflowResolver::resolve($model, $unitId)` (never from client input), then `WorkflowEngine::start($transaction, $workflow, $assignees, $actor)` inside the same database transaction that saves the record. Refuse to save when no pipeline applies.
- On approve, revert, or reject: authorize with the package's `ProcessApprovalRequest` (or `TransactionWorkflow::canBeActedOnBy`), then call the engine.
- For edit forms: `canEditWorkflowField($user, $key)` per field or section; refuse a changed value of a locked field.

## 6. Engine behavior (`WorkflowEngine`): the rules

The engine is the **only writer** of `transaction_workflows`, `workflow_step_approvers`, and `workflow_step_approver_logs`. Every action runs in one database transaction, locks the `transaction_workflows` row (`lockForUpdate`), writes assignment, log, and state together, and dispatches notifications and events **after commit**. A refused action throws `WorkflowActionException($message, $field)`, which renders as a form validation error on that field (controllers need no try/catch).

### 6.1 Who may act (single definition)

`TransactionWorkflow::canBeActedOnBy(User)` and the `pendingApprovalFor` scope share one rule:

- state is `draft` or `pending`, and the assignment row for (this transaction, current step, current cycle) is `pending`;
- **assignable step**: only the user assigned for the current cycle;
- **non-assignable step**: any user holding the step role **and** belonging to the transaction's organization;
- the host's approve permission (5.2) is checked in the policy;
- the Super Administrator may always act (but the pending scope does not include the bypass, so badges show real assignments).

### 6.2 Start (on transaction save)

State `draft`, cycle 1, current step = first step, `status_id` = first step's status. One assignment row per step for cycle 1 (assigned user for assignable steps, NULL otherwise). An assignable step **requires** a user who holds the step role and belongs to the transaction's organization. Log `created`.

### 6.3 Approve (step-1 approval is the submission)

Mark the assignment `approved`; log `submitted` for step 1, otherwise `approved`. Next step = `resumable_step_id` if set (then clear it), else the next by sequence. If none: state `approved`, `completed_at`. Otherwise state `pending`, move `current_step_id` and `status_id`, ensure the next step's assignment row exists (carrying the assignee from the latest earlier cycle). On step 1: set `submitted_at` and `submitted_by` every time (latest submission). Clear `unlocked_fields` and `reverted_by_rule_id`. Approve rules only change the button label.

### 6.4 Revert (at step S, rule R, target T)

- Offer options with `revertOptions()` (the dialog and the check use the same function): with revert rules that apply to the model, one option per rule with its targets (earlier steps only) and fields; without rules, one option: the immediately previous step with every registered field. Step 1 cannot revert.
- Validate the rule, the target, and that unlocked fields are a subset of the option's fields.
- Mark S `reverted`; log `reverted` with target and resume step.
- New cycle (`cycle + 1`); create pending assignment rows for steps T..S keeping assigned users.
- Current = T, `status_id` = T's status, `reverted_at`, `resumable_step_id` = (R.reset_approval ? null : S), `unlocked_fields` = chosen list (possibly empty; a list, not null, marks "awaiting re-approval"), `reverted_by_rule_id` = R.
- Example: revert 5 → 2. Without reset, after step 2 re-approves it goes straight to 5. With reset, it goes to 3.

### 6.5 Reject

Terminal. Mark S `rejected`, log with required remarks, state `rejected`, `rejected_at`. Current step and status stay (the record shows where it stopped). Nobody can act or edit afterwards except the Super Administrator.

### 6.6 Super Administrator overrides

- **Reopen** (rejected only): new cycle, back to step 1 with the same assignees, state `pending`, log `reopened` with required remarks.
- **Move to any step** (in-progress only). Forward: every step from the current one up to the target that is not approved in this cycle is marked approved with a log `override` (actor = Super Administrator, timestamp now); real approvals are never overwritten; from `draft`, also set `submitted_at`. Back: works like a revert without a rule (new cycle, optional unlocked fields). Always log `moved` with the target and required remarks.
- **Re-route** (change one transaction's workflow): new cycle on the new workflow at a chosen step (default step 1); steps before the restart point get `approved` rows; **approvers are reset** to newly chosen ones (required for assignable steps); log `rerouted` with old and new names. Changing a pipeline never re-routes existing transactions; only this action does.
- **Reassign** (not super-admin-only): change assigned users of steps not yet approved in the current cycle; validate role and organization for every step still to act; log `reassigned`. Approved steps keep their approver.

### 6.7 Display status

`displayStatus()`: the current step's status name, with the state beside it when it needs attention: `Draft (Rejected)`, `For Review (Reverted)` while awaiting re-approval, otherwise just the status name. Build it on the server so every list, badge, and PDF matches.

### 6.8 Edit permissions (`workflowEditScope`)

| Who                                              | When   | Editable               |
| ------------------------------------------------ | ------ | ---------------------- |
| Super Administrator                              | always | everything             |
| Not the current handler, or terminal state       |        | nothing                |
| Current handler, step is `editable`              |        | everything             |
| Current handler, awaiting re-approval            |        | only `unlocked_fields` |
| Step-1 handler, not reverted (draft or reopened) |        | everything             |
| Otherwise                                        |        | nothing                |

Keys not in `workflowEditableFields()` (for example a category column) are editable only when "everything" applies. Sections (for example line items, attachments) are keys too and must be enforced separately when they share one form: apply only the unlocked sections and say so in the UI.

### 6.9 Notifications (`WorkflowNotifier`)

After commit, send each active notification of the acted step that handles the event and applies to the model type. Recipients: `next_handlers` (assigned user, or role holders in the transaction's organization, of the new current step; nobody when finished), `creator`, `participants` (actors in the cycle of the action), plus `emails` for mail. Super Administrator reopen, move, and re-route also notify: a forward move uses the step's `approved` settings; move back, reopen, and re-route use `reverted`; the message says it was a Super Administrator action. A failed delivery is reported and never undoes the action.

### 6.10 Deleting

Transactions are soft-deleted only (Super Administrator), and their `transaction_workflows` row follows. Workflows are soft-deleted only, blocked while a pipeline uses them; in-flight transactions keep working (load workflows `withTrashed`). The resolver skips pipelines whose workflow is soft-deleted. No force delete anywhere.

## 7. Workflow administration (UI and validation)

- **Index**: list templates with step count, usage count, "Locked · N in use" badge, Active/All/Deleted filter, Create, Edit (only while unlocked), Duplicate, Delete (confirm; disabled while a pipeline uses it), Restore. Management buttons only for the Super Administrator (`can.manage` prop); the server authorizes again.
- **Create/Edit**: name, description, ordered steps (role, display status, assignable switch, can-edit switch), reorder, add, remove; per step a collapsible "Rules & notifications" panel. Rules: action, label, revert targets (earlier steps only; step 1 cannot revert), editable fields (union of registered fields of the chosen transaction types), "return here after re-approval" (= not reset), applies-to types (shown only when more than one type exists). Notifications: channel, active, events, recipients, extra emails (mail only), subject, message, applies-to. The last notification of a step cannot be removed, only switched off.
- **Update lock**: a workflow used by any transaction cannot be updated; offer "Duplicate as new template" (copies steps, rules, notifications).
- **Validation pitfalls to avoid**: steps reference each other by a client key (the step id, or `new-…` for unsaved steps), mapped to ids after saving. Do not use Laravel's `distinct` rule on doubly nested wildcards (it compares across all parents); deduplicate on save instead. Swapping step sequences must not violate unique (`workflow_id`, `sequence`) mid-update (write temporary sequences first). `name` is unique on update too.
- **Pipelines**: CRUD of (transaction type, organization or global, workflow), soft delete and restore, Super Administrator only.
- **Transaction page components** (publishable, for the host's show page): action banner and dialog (approve / revert with option, target, fields / reject; labels from approve and reject rules), Super Administrator override panel (reopen, move, change workflow with new approvers), progress tracker (each step's row from its latest cycle), audit trail (from the log, with actor names).

## 8. Authorization

- Workflow and pipeline writes, reopen, move, re-route, and transaction delete/restore: Super Administrator only (config callable). Index views may be granted by permission (`workflows:index`, `workflow-pipelines:index`).
- Every route is authorized on the server (policy via `HasMiddleware`, or the form request's `authorize()`); no form request returns `true` for a write.
- Actor fields (`created_by`, `submitted_by`, log actor) always come from `$request->user()`.

## 9. Frontend rules

- `<script setup lang="ts">`, the host's `AppLayout` with breadcrumbs, the host's `ActionButton` for every action button, Wayfinder helpers for URLs (published pages import from `@/wayfinder/...`; document that the host runs `php artisan wayfinder:generate`).
- Every page supports dark mode and phone width; nothing may be wider than the screen (no clipped tables or rows).
- **SSR-safe**: never `<Teleport to="body">` directly (Inertia's SSR output omits teleported content); ship a `BodyTeleport` that teleports only after mount. Never branch the first render on screen width, `window`, `localStorage`, or random values.
- Published files are the host's to customize; the package must not overwrite them on update (section 11).

## 10. Quality gates

- Pest + Orchestra Testbench feature tests for every rule in section 6 and every validation in section 7, including 403 tests for every protected route, using a fixture transaction model, fixture organizations, and Spatie roles. Run them on MySQL and SQLite in CI.
- Larastan level 5 with **0 errors** (typed relations with generics, `@mixin` on resources).
- Pint (laravel preset); oxfmt/oxlint or ESLint/Prettier and `vue-tsc --noEmit` for the published TS.
- Feature tests the agent writes must cover at least: start with server-side resolution; approve through all steps with correct handlers (assigned vs role pool vs other organization); pending scope; revert default and rule-based (targets, fields, reset); reject and reopen; move forward (override logs) and back; re-route with approver reset; reassign; edit scope; section enforcement; notifications (fake) including overrides; soft-delete cascade; workflow lock and duplicate; validation datasets.

## 11. Versioning and updates (the "update anytime" requirement)

- Semantic versioning with git tags; `CHANGELOG.md` per release; `UPGRADE.md` with steps for every minor and major.
- Patch and minor releases are backward compatible: no breaking changes to the `Approvable` contract, the engine's public methods, config keys, table columns, or log action names. New behavior is opt-in through config.
- Schema changes ship as new migrations with an `artisan workflow-pipelines:install --migrations` re-publish that skips files already published.
- Published Vue files: the install command publishes only missing files; `--force` is explicit. Keep logic in the PHP side and in small TS helpers that hosts can re-publish without losing layout changes.
- Development inside a host: Composer `path` repository with a symlink; release through a private VCS or Packagist repository.
- `workflow-pipelines:doctor` checks the host setup: morph map entries, config models exist, every transaction type has a global pipeline, duplicate assignment rows, workflows without steps.

## 12. Host installation guide (write it in README.md)

1. `composer require {vendor}/{package}`
2. Register a morph map for every transaction model, the user model, and package models (`Relation::enforceMorphMap`), **before** any data is written.
3. `php artisan workflow-pipelines:install` (config, migrations, Vue pages, translations), then `php artisan migrate` and `php artisan wayfinder:generate`.
4. Fill `config/workflow-pipelines.php` (models, tables, organization relation, super admin, approve permissions).
5. On each transaction model: `implements Approvable`, `use HasWorkflowApprovals`, override `workflowEditableFields()` and `workflowUrl()`.
6. In the model's store action: resolve the workflow on the server and call `start()`; in its approval controller: use `ProcessApprovalRequest` and call the engine; on its show page: mount the published banner, override panel, tracker, and audit trail.
7. Create at least one global pipeline per transaction type (seed it), or creation is refused.

### 12.3 Migrating PAMIS to the package (later, separate task)

The PAMIS tables already match section 4 (after the 2026-11-17 migrations `2026_11_17_000001` to `000004`). The package's install command must detect existing tables and skip creating them; morph values in PAMIS are class names (no morph map yet), so either register a map with the same class names or run a one-off data migration with approval.

## 13. Build phases (stop for review after each)

1. Skeleton: composer, provider, config, Testbench, CI, PHPStan, Pint.
2. Schema, models (typed relations), `Approvable`, `HasWorkflowApprovals` (without engine calls), resolver, registry.
3. Engine: start, approve, revert, reject, reopen, move, re-route, reassign; exceptions; events; tests.
4. Notifier and notification class; tests.
5. Admin HTTP layer: requests, controllers, policies, routes, actions (duplicate, sync settings); tests.
6. Published Vue pages and transaction components; SSR-safe; dark mode and phone width checked in a browser.
7. Install and doctor commands, README, UPGRADE, CHANGELOG; tag `v1.0.0`.

## 14. Non-goals for v1

Parallel steps, conditional branching on amounts, delegation and out-of-office, SLA escalations, a public JSON API. Design so they can be added later without breaking section 11.

## 15. Report format (after each phase)

What changed and why, files, tests and their results (with counts), PHPStan and type-check results, what was not verified (for example no browser), open questions. Separate verified facts from assumptions.

<!-- PROMPT END -->
