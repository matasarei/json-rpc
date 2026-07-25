<?php

declare(strict_types=1);

namespace JsonRPC;

use Closure;
use InvalidArgumentException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Server\ParameterBinder;
use ReflectionFunction;
use ReflectionMethod;

/**
 * The procedures a server exposes, and how a call is dispatched to them.
 */
final class ProcedureHandler
{
    /**
     * @var array<string, Closure>
     */
    private array $callbacks = [];

    /**
     * @var array<string, array{class-string|object, string}>
     */
    private array $classes = [];

    /**
     * @var list<array{object, list<string>}>
     */
    private array $instances = [];

    private string $beforeMethodName = '';

    private ?Closure $instanceFactory = null;

    public function __construct(private readonly ParameterBinder $binder = new ParameterBinder())
    {
    }

    public function withCallback(string $procedure, Closure $callback): self
    {
        $this->callbacks[$procedure] = $callback;

        return $this;
    }

    /**
     * @param class-string|object $class Class name, instantiated on call, or an instance
     * @param string $method Defaults to the procedure name
     */
    public function withClassAndMethod(string $procedure, string|object $class, string $method = ''): self
    {
        $this->classes[$procedure] = [$class, $method === '' ? $procedure : $method];

        return $this;
    }

    /**
     * Expose methods of an instance under their own name.
     *
     * The methods have to be listed explicitly: exposing every public method of
     * an object by reflection, as v1 did, publishes more than intended as soon
     * as the class grows a helper.
     *
     * @param list<string> $methods Names of the methods that become procedures
     */
    public function withObject(object $instance, array $methods): self
    {
        foreach ($methods as $method) {
            if (str_starts_with($method, '__')) {
                throw new InvalidArgumentException(
                    sprintf('Magic method "%s" cannot be exposed as a procedure', $method),
                );
            }

            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException(
                    sprintf('Method "%s" does not exist on %s', $method, $instance::class),
                );
            }
        }

        $this->instances[] = [$instance, array_values($methods)];

        return $this;
    }

    /**
     * Method called on the object before the procedure itself, with the
     * procedure name as argument.
     */
    public function withBeforeMethod(string $methodName): self
    {
        $this->beforeMethodName = $methodName;

        return $this;
    }

    /**
     * @param array<string, Closure> $callbacks Callbacks keyed by procedure name
     */
    public function withCallbackArray(array $callbacks): self
    {
        foreach ($callbacks as $procedure => $callback) {
            $this->withCallback($procedure, $callback);
        }

        return $this;
    }

    /**
     * @param array<string, array{class-string|object, string}> $callbacks Class and method keyed by procedure name
     */
    public function withClassAndMethodArray(array $callbacks): self
    {
        foreach ($callbacks as $procedure => $callback) {
            $this->withClassAndMethod($procedure, $callback[0], $callback[1]);
        }

        return $this;
    }

    /**
     * How to build an instance of a class registered by name.
     *
     * Bridges any container: ->withInstanceFactory($container->get(...)).
     *
     * @param Closure(class-string): object $factory
     */
    public function withInstanceFactory(Closure $factory): self
    {
        $this->instanceFactory = $factory;

        return $this;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @throws MethodNotFoundException
     * @throws InvalidParamsException
     */
    public function executeProcedure(string $procedure, array $params = []): mixed
    {
        if (isset($this->callbacks[$procedure])) {
            return $this->executeCallback($this->callbacks[$procedure], $params);
        }

        if (isset($this->classes[$procedure])) {
            [$class, $method] = $this->classes[$procedure];

            if (method_exists($class, $method)) {
                return $this->executeMethod($class, $method, $params);
            }
        }

        foreach ($this->instances as [$instance, $methods]) {
            if (in_array($procedure, $methods, true)) {
                return $this->executeMethod($instance, $procedure, $params);
            }
        }

        throw new MethodNotFoundException('Unable to find the procedure');
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function executeCallback(Closure $callback, array $params): mixed
    {
        $reflection = new ReflectionFunction($callback);

        return $reflection->invokeArgs($this->binder->bind($reflection, $params));
    }

    /**
     * @param class-string|object $class
     * @param array<array-key, mixed> $params
     */
    private function executeMethod(string|object $class, string $method, array $params): mixed
    {
        $instance = is_string($class) ? $this->instantiate($class) : $class;
        $reflection = new ReflectionMethod($instance, $method);

        if ($this->beforeMethodName !== '' && method_exists($instance, $this->beforeMethodName)) {
            $instance->{$this->beforeMethodName}($method);
        }

        return $reflection->invokeArgs($instance, $this->binder->bind($reflection, $params));
    }

    /**
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        if ($this->instanceFactory instanceof Closure) {
            return ($this->instanceFactory)($class);
        }

        return new $class();
    }
}
