<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    protected $table = 'workflow_steps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assignable' => 'boolean',
            'editable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(static function (self $step): void {
            if (! $step->notifications()->exists()) {
                $step->notifications()->create([
                    'channel' => 'database',
                    'events' => ['approved', 'reverted', 'rejected'],
                    'recipients' => ['next_handlers', 'creator'],
                    'is_active' => true,
                ]);
            }
        });
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function role(): BelongsTo
    {
        /** @var class-string<Model> $roleModel */
        $roleModel = (string) config('workflow-pipelines.models.role');

        return $this->belongsTo($roleModel, 'role_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function displayStatus(): BelongsTo
    {
        /** @var class-string<Model> $statusModel */
        $statusModel = (string) config('workflow-pipelines.models.status');

        return $this->belongsTo($statusModel, 'status_id');
    }

    /** @return HasMany<WorkflowStepRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(WorkflowStepRule::class);
    }

    /** @return HasMany<WorkflowStepNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(WorkflowStepNotification::class);
    }

    /** @return HasMany<WorkflowStepApprover, $this> */
    public function approvers(): HasMany
    {
        return $this->hasMany(WorkflowStepApprover::class);
    }

    public function getTable(): string
    {
        return (string) config('workflow-pipelines.table_prefix', '').parent::getTable();
    }
}
