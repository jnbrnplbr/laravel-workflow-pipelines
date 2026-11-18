<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Jnbrnplbr\WorkflowPipelines\Concerns\HasWorkflowApprovals;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;
use Traversable;

final class ApprovableModelRegistry
{
    /** @var array<class-string<Approvable>, true> */
    private array $models = [];

    /**
     * @param  class-string  $modelClass
     */
    public function register(string $modelClass): void
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Approvable::class)) {
            throw new InvalidArgumentException("[$modelClass] must implement the Approvable contract.");
        }

        /** @var class-string<Approvable> $modelClass */
        $this->models[$modelClass] = true;
    }

    /**
     * @return list<class-string<Approvable>>
     */
    public function all(): array
    {
        $configured = config('workflow-pipelines.approvables', []);

        if (is_array($configured) && $configured !== []) {
            foreach ($configured as $modelClass) {
                if (is_string($modelClass)) {
                    $this->register($modelClass);
                }
            }

            return array_keys($this->models);
        }

        if ($this->models !== []) {
            return array_keys($this->models);
        }

        return $this->discover();
    }

    /** @return list<class-string<Approvable>> */
    public function discover(): array
    {
        $modelsPath = app_path('Models');

        if (! is_dir($modelsPath)) {
            return [];
        }

        $namespace = function_exists('app') ? app()->getNamespace().'Models\\' : 'App\\Models\\';
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modelsPath));

        /** @var Traversable<\SplFileInfo> $files */
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($modelsPath) + 1, -4);
            $modelClass = $namespace.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Approvable::class)) {
                continue;
            }

            $reflection = new ReflectionClass($modelClass);

            if ($reflection->isAbstract() || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            if (! in_array(HasWorkflowApprovals::class, class_uses_recursive($modelClass), true)) {
                continue;
            }

            /** @var class-string<Approvable> $modelClass */
            $classes[] = $modelClass;
        }

        sort($classes);

        return array_values(array_unique($classes));
    }
}
