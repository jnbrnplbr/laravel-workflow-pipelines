<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jnbrnplbr\WorkflowPipelines\Concerns\HasWorkflowApprovals;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;

class FixtureTransaction extends Model implements Approvable
{
    use HasWorkflowApprovals;

    protected $table = 'transactions';

    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    public static function workflowEditableFields(): array
    {
        return ['title' => 'Title'];
    }
}
