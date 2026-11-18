<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class InstallCommand extends Command
{
    protected $signature = 'workflow-pipelines:install
        {--migrations : Publish only migration files that are not already present}
        {--force : Replace existing config, frontend, and translation files}';

    protected $description = 'Install the workflow pipelines package files into this application';

    /** @var list<string> */
    private array $migrationOrder = [
        'create_workflows_table.php.stub',
        'create_workflow_steps_table.php.stub',
        'create_workflow_step_rules_table.php.stub',
        'create_workflow_step_notifications_table.php.stub',
        'create_workflow_pipelines_table.php.stub',
        'create_transaction_workflows_table.php.stub',
        'create_workflow_step_approvers_table.php.stub',
        'create_workflow_step_approver_logs_table.php.stub',
    ];

    public function handle(): int
    {
        $this->publishMigrations();

        if (! $this->option('migrations')) {
            $this->publishFile(
                dirname(__DIR__, 2).'/config/workflow-pipelines.php',
                config_path('workflow-pipelines.php'),
            );
            $this->publishDirectory(
                dirname(__DIR__, 2).'/resources/js/Pages/Vendor/WorkflowPipelines',
                resource_path('js/Pages/'.trim((string) config('workflow-pipelines.inertia.pages_path', 'Vendor/WorkflowPipelines'), '/')),
            );
            $this->publishFile(
                dirname(__DIR__, 2).'/resources/js/wayfinder/workflow-pipelines.ts',
                resource_path('js/wayfinder/workflow-pipelines.ts'),
            );
            $this->publishDirectory(
                dirname(__DIR__, 2).'/resources/lang/en',
                lang_path('vendor/workflow-pipelines'),
            );
        }

        $this->components->info('Laravel Workflow Pipelines files are ready. Run `php artisan migrate` next.');

        return self::SUCCESS;
    }

    private function publishMigrations(): void
    {
        $sourceDirectory = dirname(__DIR__, 2).'/database/migrations';
        $targetDirectory = database_path('migrations');
        File::ensureDirectoryExists($targetDirectory);
        $published = 0;
        $skipped = 0;
        $base = now();

        foreach ($this->migrationOrder as $index => $stub) {
            $source = $sourceDirectory.'/'.$stub;

            if (! is_file($source)) {
                throw new RuntimeException("Workflow Pipelines migration stub [$stub] is missing.");
            }

            $migrationName = substr($stub, 0, -strlen('.php.stub'));
            $matches = glob($targetDirectory.'/*_'.$migrationName.'.php') ?: [];

            if ($matches !== []) {
                $skipped++;

                continue;
            }

            $timestamp = $base->copy()->addSeconds($index)->format('Y_m_d_His');
            $target = $targetDirectory.'/'.$timestamp.'_'.$migrationName.'.php';
            File::copy($source, $target);
            $published++;
        }

        $this->line("Migrations: {$published} published, {$skipped} already present.");
    }

    private function publishFile(string $source, string $target): void
    {
        if (! is_file($source)) {
            throw new RuntimeException("Workflow Pipelines source file [$source] is missing.");
        }

        if (is_file($target) && ! $this->option('force')) {
            $this->line("Skipped existing file: {$target}");

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy($source, $target);
        $this->line("Published: {$target}");
    }

    private function publishDirectory(string $source, string $target): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Workflow Pipelines source directory [$source] is missing.");
        }

        foreach (File::allFiles($source) as $file) {
            $relative = str_replace($source.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $this->publishFile($file->getPathname(), $target.'/'.$relative);
        }
    }
}
