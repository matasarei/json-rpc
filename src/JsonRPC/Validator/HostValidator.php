<?php

namespace JsonRPC\Validator;

use JsonRPC\Exception\AccessDeniedException;

/**
 * Class HostValidator
 *
 * @package JsonRPC\Validator
 * @author  Frederic Guillot
 */
class HostValidator
{
    /**
     * Validate
     *
     * @param  array  $hosts
     * @param  string $remoteAddress
     *
     * @throws AccessDeniedException
     */
    public static function validate(array $hosts, $remoteAddress)
    {
        if (!empty($hosts)) {
            foreach ($hosts as $host) {
                if (self::ipMatch($remoteAddress, $host)) {
                    return;
                }
            }
            throw new AccessDeniedException('Access Forbidden');
        }
    }

    /**
     * Validate remoteAddress match host
     *
     * @param $remoteAddress
     * @param $host
     *
     * @return bool
     */
    public static function ipMatch($remoteAddress, $host)
    {
        $host = trim((string)$host);
        if (strpos($host, '/') !== false) {
            list($network, $mask) = explode('/', $host);
            if (self::netMatch((string)$remoteAddress, (string)$network, (string)$mask)) {
                return true;
            }
        }

        if ($host === $remoteAddress) {
            return true;
        }

        return false;
    }

    /**
     * validate the ipAddress in network
     *
     * @param string $clientIp
     * @param string $networkIp
     * @param string $mask
     *
     * @return bool
     */
    public static function netMatch($clientIp, $networkIp, $mask)
    {
        $mask1 = 32 - (int)$mask;
        return ((ip2long((string)$clientIp) >> $mask1) == (ip2long((string)$networkIp) >> $mask1));
    }
}
