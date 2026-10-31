<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Models\JsonObject;

final class Json
{
    public static function object(JsonObject $object): \stdClass
    {
        $result = new \stdClass();
        foreach ($object->value as $key => $value) { $result->{$key} = self::wire($value); }
        return $result;
    }
    public static function wire(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(self::wire(...), $value); }
        $result = new \stdClass();
        foreach ($value as $key => $entry) { $result->{(string) $key} = self::wire($entry); }
        return $result;
    }
    public static function schema(JsonObject $schema): \stdClass
    {
        return self::schemaNode(self::object($schema));
    }
    private static function schemaNode(\stdClass $node): \stdClass
    {
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, ['properties', '$defs', 'definitions', 'patternProperties', 'dependentSchemas'], true)) {
                if ($value === []) { $value = new \stdClass(); }
                if ($value instanceof \stdClass) {
                    foreach (get_object_vars($value) as $name => $entry) {
                        if ($entry instanceof \stdClass) { $value->{$name} = self::schemaNode($entry); }
                    }
                }
                $node->{$key} = $value;
            } elseif ($value instanceof \stdClass) { $node->{$key} = self::schemaNode($value); }
            elseif (is_array($value)) {
                foreach ($value as $index => $entry) { if ($entry instanceof \stdClass) { $value[$index] = self::schemaNode($entry); } }
                $node->{$key} = $value;
            }
        }
        return $node;
    }
    public static function portable(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $entry) { $out[$key] = self::portable($entry); }
            return $out;
        }
        if (is_array($value)) { return array_map(self::portable(...), $value); }
        return $value;
    }
}