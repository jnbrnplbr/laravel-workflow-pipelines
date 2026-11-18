<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Exceptions;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;

final class WorkflowActionException extends ValidationException
{
    public readonly string $field;

    public function __construct(string $message, ?string $field = null)
    {
        $this->field = $field ?? 'workflow';
        $validator = app(ValidationFactory::class)->make([], []);
        $validator->errors()->add($this->field, $message);

        parent::__construct($validator);
    }
}
