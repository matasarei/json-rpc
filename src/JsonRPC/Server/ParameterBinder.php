<?php

declare(strict_types=1);

namespace JsonRPC\Server;

use JsonRPC\Exception\InvalidParamsException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Matches the parameters of a request to the signature of a procedure.
 */
final readonly class ParameterBinder
{
    /**
     * @param array<array-key, mixed> $params Parameters of the request
     *
     * @return array<array-key, mixed> Arguments to invoke the procedure with
     *
     * @throws InvalidParamsException
     */
    public function bind(ReflectionFunctionAbstract $procedure, array $params): array
    {
        $given = count($params);

        if ($given < $procedure->getNumberOfRequiredParameters()) {
            throw new InvalidParamsException('Wrong number of arguments');
        }

        // A variadic procedure takes as many arguments as it is given, even
        // though reflection counts its variadic parameter only once.
        if (!$procedure->isVariadic() && $given > $procedure->getNumberOfParameters()) {
            throw new InvalidParamsException('Too many arguments');
        }

        $arguments = array_is_list($params) ? $params : $this->named($params, $procedure->getParameters());

        $this->checkTypes($arguments, $procedure->getParameters());

        return $arguments;
    }

    /**
     * Reject values that do not fit a declared scalar type.
     *
     * Without this, the TypeError PHP raises while binding the arguments would
     * reach the client as a generic internal error, hiding that the request
     * itself was wrong. Only unambiguous cases are checked; anything else is
     * left to PHP.
     *
     * @param array<array-key, mixed> $arguments
     * @param list<ReflectionParameter> $signature
     *
     * @throws InvalidParamsException
     */
    private function checkTypes(array $arguments, array $signature): void
    {
        foreach ($signature as $position => $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $parameter->isVariadic()) {
                continue;
            }

            $key = array_key_exists($parameter->getName(), $arguments) ? $parameter->getName() : $position;

            if (!array_key_exists($key, $arguments)) {
                continue;
            }

            $value = $arguments[$key];

            if ($value === null && $type->allowsNull()) {
                continue;
            }

            $matches = match ($type->getName()) {
                'int' => is_int($value),
                // An integer is widened to float, even under strict types.
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                default => true,
            };

            if (!$matches) {
                throw new InvalidParamsException(sprintf(
                    'Invalid type for argument: %s, %s expected',
                    $parameter->getName(),
                    $type->getName(),
                ));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     * @param list<ReflectionParameter> $signature
     *
     * @return array<string, mixed>
     *
     * @throws InvalidParamsException
     */
    private function named(array $params, array $signature): array
    {
        $arguments = [];

        foreach ($signature as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $arguments[$name] = $params[$name];

                continue;
            }

            if (!$parameter->isDefaultValueAvailable()) {
                throw new InvalidParamsException('Missing argument: ' . $name);
            }

            $arguments[$name] = $parameter->getDefaultValue();
        }

        $undefined = array_diff_key($params, $arguments);

        if ($undefined !== []) {
            throw new InvalidParamsException('Undefined arguments: ' . implode(', ', array_keys($undefined)));
        }

        return $arguments;
    }
}
