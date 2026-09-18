<?php

namespace App\Services;

class BuildInfoService
{
    private static function getData(): array
    {
        $versionFile = base_path('VERSION.json');

        if (!file_exists($versionFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($versionFile), true);
        return is_array($data) ? $data : [];
    }

    public static function getVersion(): string
    {
        return self::getData()['version'] ?? config('app.Version', 'Unknown');
    }

    public static function getBuildNumber(): string
    {
        $data = self::getData();
        $count = (int) ($data['commitCount'] ?? 0);
        $hash = $data['commitHash'] ?? 'unknown';

        return $count > 0 ? "{$count} (g{$hash})" : 'dev';
    }

    public static function getBuildInfo(): array
    {
        $data = self::getData();

        return [
            'version' => self::getVersion(),
            'buildNumber' => self::getBuildNumber(),
            'commitCount' => (int) ($data['commitCount'] ?? 0),
            'commitHash' => $data['commitHash'] ?? 'unknown',
            'buildTimestamp' => $data['buildTimestamp'] ?? 'unknown',
        ];
    }
}