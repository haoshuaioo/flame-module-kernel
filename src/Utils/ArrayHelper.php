<?php

namespace Flame\Utils;

class ArrayHelper
{
    /**
     * 获取嵌套配置值
     *
     * @param array $array 配置数组
     * @param string $key 键名（支持点号分隔）
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 设置嵌套配置值
     *
     * @param array $array 配置数组
     * @param string $key 键名（支持点号分隔）
     * @param mixed $value 值
     * @return array
     */
    static public function setNestedValue(array $array, string $key, mixed $value): array
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }

        return $array;
    }

    /**
     * 递归合并数组（保留原有结构，相同键值内容合并为数组内容）
     *
     * @param array $array1 原数组
     * @param array $array2 新数组
     * @return array 递归合并后的数组
     */
    static public function arrayMergeRecursive(array $array1, array $array2): array
    {
        return array_merge_recursive($array1, $array2);
    }


    /**
     * 递归合并数组（覆盖原有结构）
     *
     * @param array $array1 原数组
     * @param array $array2 新数组
     * @return array 合并后的数组
     */
    static public function arrayMergeOverwrite(array $array1, array &$array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::arrayMergeOverwrite($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}