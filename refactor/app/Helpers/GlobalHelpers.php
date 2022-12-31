<?php

namespace App\Helpers;

class GlobalHelpers
{
    public static function in_array_all($needles, $haystack)
    {
        return empty(array_diff($needles, $haystack));
    }
}
