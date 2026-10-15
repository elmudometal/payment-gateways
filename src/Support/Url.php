<?php

namespace Arca\PaymentGateways\Support;

class Url
{
    /**
     * Whether a gateway can reach the URL: HTTPS on a host that is not local or private.
     */
    public static function isPublic(string $url): bool
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '' || $host === 'localhost') {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.internal', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
