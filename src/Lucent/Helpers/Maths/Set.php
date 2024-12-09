<?php

namespace Lucent\Helpers\Maths;


class Set
{

    //Union returns an array containing both sets of values;
    public static function union(array $a, array $b): array
    {
        return array_merge($a,$b);
    }

    //The intersection returns elements that are in 'A' and 'B'.
    public static function intersect(array $a, array $b, SetComparison $type = SetComparison::ValuesToValues): array
    {

        //TODO add later

        return [];
    }

    //The difference returns elements that are in 'A' but not 'B'.
    public static function difference(array $a, array $b, SetComparison $type = SetComparison::ValuesToValues) : array
    {
        $output = [];

        switch ($type) {

            case SetComparison::KeysToKeys:

                foreach ($a as $key => $value) {
                    if (!array_key_exists($key, $b)) {
                        array_push($output, $key);
                    }
                }
                break;

            case SetComparison::ValuesToKeys:

                foreach ($a as $item) {
                    if (!array_key_exists($item, $b)) {
                        array_push($output, $item);
                    }
                }
                break;

            //Default comparison is value to value;
            default:
                foreach ($a as $item) {
                    if (!in_array($item, $b)) {
                        array_push($output, $item);
                    }
                }
        }


        return $output;
    }

}