<?php

namespace Elielelie\ActiveMQ\Helpers;

use DateInterval;

class IntervalToMilliseconds
{
    public static function convert(DateInterval $interval)
    {
        $milliseconds = 0;

        if ($interval->y) {
            $milliseconds += $interval->y * 31536000000;
        }
        if ($interval->m) {
            $milliseconds += $interval->m * 2678400000;
        }
        if ($interval->d) {
            $milliseconds += $interval->d * 86400000;
        }
        if ($interval->h) {
            $milliseconds += $interval->h * 3600000;
        }
        if ($interval->i) {
            $milliseconds += $interval->i * 60000;
        }
        if ($interval->s) {
            $milliseconds += $interval->s * 1000;
        }

        return $milliseconds;
    }
}
