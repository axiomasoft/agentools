<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Budget;

/** Preserve JSON object/list distinctions (including nested empty objects) across restart. */
final class RequestCodec
{
    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        $object=json_decode($json,flags:JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) { throw new \InvalidArgumentException('Admission object required'); }
        $content=[];
        foreach (get_object_vars($object) as $key=>$value) { $content[$key]=self::value($value); }
        return \AgenToolsPlatform\Core\Usage\Values::object($content);
    }
    private static function value(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            if (get_object_vars($value)===[]) { return new \stdClass(); }
            $content=[];
            foreach (get_object_vars($value) as $key=>$child) { $content[$key]=self::value($child); }
            return $content;
        }
        if (is_array($value)) { return array_map(self::value(...),$value); }
        return $value;
    }
}
