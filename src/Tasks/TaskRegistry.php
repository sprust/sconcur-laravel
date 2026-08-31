<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * The pool's task list, read from `sconcur.tasks.list`, and the live instance of each
 * task.
 *
 * Instances are resolved lazily and kept, because a task carries state between ticks
 * (the minute cron last ran, the timer a cleanup pass is due on). forget() drops one so
 * the next tick resolves a fresh instance — that, and nothing more, is what restarting
 * a task means here.
 */
class TaskRegistry
{
    /** @var array<string, TaskDefinitionDto> */
    protected array $definitions = [];

    /** @var array<string, TaskInterface> */
    protected array $instances = [];

    /**
     * @param array<int|string, mixed> $list
     */
    public function __construct(protected Container $container, array $list)
    {
        foreach (array_values($list) as $index => $entry) {
            $definition = $this->readDefinition($entry, $index);

            if (isset($this->definitions[$definition->name])) {
                throw new RuntimeException(
                    'sconcur: config("sconcur.tasks.list") has two tasks named ' . $definition->name,
                );
            }

            $this->definitions[$definition->name] = $definition;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    public function definition(string $name): TaskDefinitionDto
    {
        return $this->definitions[$name]
            ?? throw new RuntimeException('sconcur: no task named ' . $name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function task(string $name): TaskInterface
    {
        return $this->instances[$name] ??= $this->container->make($this->definition($name)->task);
    }

    /** Drops the instance so the next tick runs a freshly built one. */
    public function forget(string $name): void
    {
        unset($this->instances[$name]);
    }

    protected function readDefinition(mixed $entry, int $index): TaskDefinitionDto
    {
        if (!is_array($entry)) {
            throw new RuntimeException(
                sprintf('sconcur: config("sconcur.tasks.list")[%d] must be an array', $index),
            );
        }

        $name = (string) ($entry['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException(
                sprintf('sconcur: config("sconcur.tasks.list")[%d] requires a "name"', $index),
            );
        }

        $task = (string) ($entry['task'] ?? '');

        if (!is_subclass_of($task, TaskInterface::class)) {
            throw new RuntimeException(
                sprintf('sconcur: task "%s" must name a class implementing %s', $name, TaskInterface::class),
            );
        }

        // Every interval is optional and every default is safe on its own: a task with
        // no numbers configured polls once a second and backs off three, rather than
        // spinning the pool at full speed on a typo.
        return new TaskDefinitionDto(
            name: $name,
            task: $task,
            idle: (float) ($entry['idle'] ?? 1),
            busy: (float) ($entry['busy'] ?? 0),
            backoff: (float) ($entry['backoff'] ?? 3),
        );
    }
}
