<?php

declare(strict_types=1);

namespace JsonRPC\Server;

use JsonRPC\Exception\InvalidParamsException;
use ReflectionFunctionAbstract;

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

        if ($given > $procedure->getNumberOfParameters()) {
            throw new InvalidParamsException('Too many arguments');
        }

        if (array_is_list($params)) {
            return $params;
        }

        return $this->named($params, $procedure->getParameters());
    }

    /**
     * @param array<array-key, mixed> $params
     * @param list<\ReflectionParameter> $signature
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
