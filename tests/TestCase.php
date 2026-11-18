<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Jnbrnplbr\WorkflowPipelines\Tests\Support\HasWorkflowDatabase;
use Jnbrnplbr\WorkflowPipelines\WorkflowPipelinesServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use HasWorkflowDatabase;

    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [WorkflowPipelinesServiceProvider::class];
    }
}
