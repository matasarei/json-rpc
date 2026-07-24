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
        $host = trim($host);
        if (strpos($host, '/') !== false) {
            list($network, $mask) = explode('/', $host, 2);
            if (self::netMatch($remoteAddress, $network, $mask)) {
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
     * Only IPv4 CIDR ranges are supported. Any malformed input (non-IPv4
     * address, out-of-range or non-numeric mask) fails closed (returns false).
     *
     * @param string $clientIp
     * @param string $networkIp
     * @param string $mask
     *
     * @return bool
     */
    public static function netMatch($clientIp, $networkIp, $mask)
    {
        $client = ip2long($clientIp);
        $network = ip2long($networkIp);

        if ($client === false || $network === false || ! is_numeric($mask)) {
            return false;
        }

        $mask = (int) $mask;

        if ($mask < 0 || $mask > 32) {
            return false;
        }

        if ($mask === 0) {
            return true;
        }

        $shift = 32 - $mask;

        return ($client >> $shift) === ($network >> $shift);
    }
}
