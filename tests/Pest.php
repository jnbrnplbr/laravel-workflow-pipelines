<?php

declare(strict_types=1);

use Jnbrnplbr\WorkflowPipelines\Tests\TestCase;

uses(TestCase::class)->in('Feature');

beforeEach(function (): void {
    $this->createWorkflowDatabase();
})->in('Feature');

afterEach(function (): void {
    $this->dropWorkflowDatabase();
})->in('Feature');
