<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jnbrnplbr\WorkflowPipelines\Http\Controllers\WorkflowController;
use Jnbrnplbr\WorkflowPipelines\Http\Controllers\WorkflowPipelineController;

Route::middleware(config('workflow-pipelines.routes.middleware', ['web', 'auth']))
    ->prefix(trim((string) config('workflow-pipelines.routes.prefix', 'development/pipelines'), '/'))
    ->name((string) config('workflow-pipelines.routes.name', 'development.pipelines.'))
    ->group(function (): void {
        Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('/workflows/create', [WorkflowController::class, 'create'])->name('workflows.create');
        Route::post('/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
        Route::get('/workflows/{workflow}/edit', [WorkflowController::class, 'edit'])->name('workflows.edit');
        Route::put('/workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
        Route::post('/workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])->name('workflows.duplicate');
        Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
        Route::post('/workflows/{workflowId}/restore', [WorkflowController::class, 'restore'])->name('workflows.restore');

        Route::get('/workflow-pipelines', [WorkflowPipelineController::class, 'index'])->name('workflow-pipelines.index');
        Route::post('/workflow-pipelines', [WorkflowPipelineController::class, 'store'])->name('workflow-pipelines.store');
        Route::put('/workflow-pipelines/{pipeline}', [WorkflowPipelineController::class, 'update'])->name('workflow-pipelines.update');
        Route::delete('/workflow-pipelines/{pipeline}', [WorkflowPipelineController::class, 'destroy'])->name('workflow-pipelines.destroy');
        Route::post('/workflow-pipelines/{pipelineId}/restore', [WorkflowPipelineController::class, 'restore'])->name('workflow-pipelines.restore');
    });
