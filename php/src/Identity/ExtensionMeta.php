<?php

declare(strict_types=1);

namespace AgenTools\Identity;

final class ExtensionMeta
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    public static function actionData(array $meta): ?array
    {
        $data = $meta[ExtensionIdentity::DATA_KEY] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $actionData = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $actionData[$key] = $value;
        }

        return $actionData;
    }
}
