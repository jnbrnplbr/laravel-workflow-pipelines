<?php

declare(strict_types=1);

it('registers the package defaults', function (): void {
    expect(config('workflow-pipelines.table_prefix'))->toBe('')
        ->and(config('workflow-pipelines.routes.enabled'))->toBeTrue()
        ->and(config('workflow-pipelines.notifications.enabled'))->toBeTrue();
});
