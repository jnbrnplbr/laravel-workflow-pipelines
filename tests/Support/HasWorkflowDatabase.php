<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait HasWorkflowDatabase
{
    protected function createWorkflowDatabase(): void
    {
        config(['workflow-pipelines.table_prefix' => '']);

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('statuses', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title')->nullable();
            $table->softDeletes();
        });

        foreach ($this->workflowMigrationFiles() as $migrationFile) {
            $migration = require dirname(__DIR__, 2).'/database/migrations/'.$migrationFile;
            $migration->up();
        }
    }

    protected function dropWorkflowDatabase(): void
    {
        foreach (array_reverse($this->workflowMigrationFiles()) as $migrationFile) {
            $migration = require dirname(__DIR__, 2).'/database/migrations/'.$migrationFile;
            $migration->down();
        }

        foreach (['transactions', 'statuses', 'organizations', 'roles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @return list<string> */
    private function workflowMigrationFiles(): array
    {
        return [
            'create_workflows_table.php.stub',
            'create_workflow_steps_table.php.stub',
            'create_workflow_step_rules_table.php.stub',
            'create_workflow_step_notifications_table.php.stub',
            'create_workflow_pipelines_table.php.stub',
            'create_transaction_workflows_table.php.stub',
            'create_workflow_step_approvers_table.php.stub',
            'create_workflow_step_approver_logs_table.php.stub',
        ];
    }
}
