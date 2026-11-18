<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class FixtureUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = ['id'];

    public $timestamps = false;
}
