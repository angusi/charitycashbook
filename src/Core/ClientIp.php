<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Works out the client's IP address for logging.
 *
 * X-Forwarded-For is only believed when the request came from a configured reverse
 * proxy; otherwise any client could put whatever it likes in the logs.
 */
final class ClientIp
{
    /** @var string[] */
    private static array $trustedProxies = [];

    /**
     * @param string[] $trustedProxies IP addresses of reverse proxies in front of the app
     */
    public static function configure(array $trustedProxies): void
    {
        self::$trustedProxies = array_values(array_filter(array_map('strval', $trustedProxies)));
    }

    public static function get(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $remoteAddr = $server['REMOTE_ADDR'] ?? null;
        $ip = $remoteAddr;

        if ($remoteAddr && in_array($remoteAddr, self::$trustedProxies, true) && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR']));
            // Walk back from the nearest hop, skipping our own proxies
            for ($i = count($forwarded) - 1; $i >= 0; $i--) {
                if ($forwarded[$i] !== '' && !in_array($forwarded[$i], self::$trustedProxies, true)) {
                    $ip = $forwarded[$i];
                    break;
                }
            }
        }

        return $ip ? substr($ip, 0, 45) : null;
    }
}
