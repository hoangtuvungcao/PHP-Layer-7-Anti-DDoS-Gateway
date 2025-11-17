<?php

class FingerprintUtils
{
    public static function normalize(array $fingerprint): array
    {
        ksort($fingerprint);

        foreach ($fingerprint as $key => $value) {
            if (is_array($value)) {
                $fingerprint[$key] = self::normalize($value);
            }
        }

        return $fingerprint;
    }

    public static function hash(array $fingerprint): string
    {
        return hash('sha256', json_encode(self::normalize($fingerprint)));
    }
}
