<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

use Psr\Container\ContainerInterface;

/**
 * Simple PSR-11 container for dependency injection
 */
class Container implements ContainerInterface
{
    private array $definitions = [];
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->definitions[$id])) {
                throw new \RuntimeException("No definition found for: $id");
            }
            $this->instances[$id] = ($this->definitions[$id])($this);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->instances[$id]);
    }
}
