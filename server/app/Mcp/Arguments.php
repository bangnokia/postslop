<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Core\Failure;

/** Checks the JSON Schema subset used by our tools, without coercing JSON values. */
// Input validation branches on JSON types and their declared constraints.
// @mago-expect lint:cyclomatic-complexity
final class Arguments
{
    public static function validate(mixed $value, array $schema, string $field = 'arguments'): mixed
    {
        $type = match (true) {
            $value instanceof \stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            $value === null => 'null',
            default => 'unsupported',
        };
        if (!in_array($type, (array) $schema['type'], true)) {
            throw Failure::invalid($field . ' must be ' . implode(' or ', (array) $schema['type']) . '.');
        }
        if (array_key_exists('enum', $schema) && !in_array($value, $schema['enum'], true)) {
            throw Failure::invalid($field . ' must be one of: ' . implode(', ', $schema['enum']) . '.');
        }
        if ($type === 'object') {
            return self::object($value, $schema, $field);
        }
        if ($type === 'array') {
            self::range(count($value), $schema['minItems'] ?? 0, $schema['maxItems'] ?? PHP_INT_MAX, $field);
            return array_map(static fn($item): mixed => self::validate($item, $schema['items'], $field . '[]'), $value);
        }
        if ($type === 'string') {
            self::range(
                mb_strlen($value),
                $schema['minLength'] ?? 0,
                $schema['maxLength'] ?? PHP_INT_MAX,
                $field . ' length',
            );
            if (array_key_exists('pattern', $schema) && preg_match('~' . $schema['pattern'] . '~D', $value) !== 1) {
                throw Failure::invalid($field . ' has an invalid format. ' . ($schema['description'] ?? ''));
            }
        }
        if ($type === 'integer') {
            self::range($value, $schema['minimum'] ?? PHP_INT_MIN, $schema['maximum'] ?? PHP_INT_MAX, $field);
        }
        return $value;
    }

    private static function object(\stdClass $value, array $schema, string $field): array
    {
        $properties = (array) $schema['properties'];
        foreach ($schema['required'] as $name) {
            if (!property_exists($value, $name)) {
                throw Failure::invalid($field . '.' . $name . ' is required.');
            }
        }
        $result = [];
        foreach (get_object_vars($value) as $name => $item) {
            if (!array_key_exists($name, $properties)) {
                // Do not echo unrecognized input names or values; they may contain secrets.
                throw Failure::invalid($field . ' contains an unknown field. Use only the fields in inputSchema.');
            }
            $result[$name] = self::validate($item, $properties[$name], $field . '.' . $name);
        }
        return $result;
    }

    private static function range(int $value, int $min, int $max, string $field): void
    {
        if ($value < $min || $value > $max) {
            throw Failure::invalid($field . ' must be between ' . $min . ' and ' . $max . '.');
        }
    }
}
