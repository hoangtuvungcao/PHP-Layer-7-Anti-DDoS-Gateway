<?php

class IPUtils
{
    public static function matches(string $ip, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::match($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function match(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        if (strpos($pattern, '/') !== false) {
            return self::cidrMatch($ip, $pattern);
        }

        return strcasecmp($ip, $pattern) === 0;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::cidrMatchV6($ip, $subnet, $mask);
        }

        return self::cidrMatchV4($ip, $subnet, $mask);
    }

    private static function cidrMatchV4(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskBits = -1 << (32 - $mask);

        return ($ipLong & $maskBits) === ($subnetLong & $maskBits);
    }

    private static function cidrMatchV6(string $ip, string $subnet, int $mask): bool
    {
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $maskByte = (~0 << (8 - $bits)) & 0xFF;

        return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
    }
}
