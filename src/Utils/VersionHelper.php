<?php

namespace Flame\Utils;

class VersionHelper
{
    /**
     * 检查版本是否满足约束条件（支持 Composer 风格的版本约束）
     * @param string $installedVersion 已安装的版本
     * @param string $constraint 版本约束表达式
     * @return bool
     */
    static public function satisfiesVersionConstraint(string $installedVersion, string $constraint): bool
    {
        $installedVersion = self::normalizeVersion($installedVersion);

        // 去除约束中的空格并分割多个条件
        $constraint = trim($constraint);

        // 处理特殊约束
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        // 分割多个约束条件（空格分隔表示 AND 关系）
        $constraints = preg_split('/\s+/', $constraint);

        foreach ($constraints as $singleConstraint) {
            if (!self::checkSingleConstraint($installedVersion, $singleConstraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查单个版本约束
     * @param string $installedVersion 标准化后的版本号
     * @param string $constraint 单个约束条件
     * @return bool
     */
    static protected function checkSingleConstraint(string $installedVersion, string $constraint): bool
    {
        // 精确匹配
        if (preg_match('/^=?=?=?=?v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '==');
        }

        // >= 约束
        if (preg_match('/^>=\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '>=');
        }

        // <= 约束
        if (preg_match('/^<=\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '<=');
        }

        // > 约束
        if (preg_match('/^>\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '>');
        }

        // < 约束
        if (preg_match('/^<\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '<');
        }

        // != 约束
        if (preg_match('/^!=\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            return version_compare($installedVersion, self::normalizeVersion($matches[1]), '!=');
        }

        // ^ 约束（兼容主要版本）
        // ^1.2.3 等价于 >=1.2.3 <2.0.0
        // ^0.2.3 等价于 >=0.2.3 <0.3.0
        if (preg_match('/^\^\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            $baseVersion = self::normalizeVersion($matches[1]);
            $parts = explode('.', $baseVersion);
            $major = (int)($parts[0] ?? 0);

            if ($major === 0) {
                // ^0.x.y 等价于 >=0.x.y <0.(x+1).0
                $nextMinor = ((int)($parts[1] ?? 0)) + 1;
                $upperBound = "0.{$nextMinor}.0";
                return version_compare($installedVersion, $baseVersion, '>=') &&
                    version_compare($installedVersion, $upperBound, '<');
            } else {
                // ^x.y.z 等价于 >=x.y.z <(x+1).0.0
                $nextMajor = $major + 1;
                $upperBound = "{$nextMajor}.0.0";
                return version_compare($installedVersion, $baseVersion, '>=') &&
                    version_compare($installedVersion, $upperBound, '<');
            }
        }

        // ~ 约束（兼容次要版本）
        // ~1.2.3 等价于 >=1.2.3 <1.3.0
        // ~1.2 等价于 >=1.2.0 <1.3.0
        if (preg_match('/^~\s*v?(\d[\d.]*[a-zA-Z0-9\-]*)$/i', $constraint, $matches)) {
            $baseVersion = self::normalizeVersion($matches[1]);
            $parts = explode('.', $baseVersion);
            $major = (int)($parts[0] ?? 0);
            $minor = (int)($parts[1] ?? 0);

            // ~x.y.z 或 ~x.y 等价于 >=x.y.z <x.(y+1).0
            $nextMinor = $minor + 1;
            $upperBound = "$major.$nextMinor.0";
            return version_compare($installedVersion, $baseVersion, '>=') &&
                version_compare($installedVersion, $upperBound, '<');
        }

        // 默认返回 false（无法解析的约束）
        trace("[FlameModule] WARNING: Unrecognized version constraint '{$constraint}'", 'warning');
        return false;
    }

    /**
     * 标准化版本号（移除 v 前缀等）
     * @param string $version
     * @return string
     */
    static public function normalizeVersion(string $version): string
    {
        $version = trim($version);
        // 移除 v 或 V 前缀
        return preg_replace('/^v/i', '', $version);
    }

    /**
     * 解析依赖字符串，提取模块名和版本约束
     * 
     * 支持以下格式：
     * - 'ModuleName' => ['ModuleName', '*']
     * - 'ModuleName@^1.0' => ['ModuleName', '^1.0']
     * - 'ModuleName@>=2.0 <3.0' => ['ModuleName', '>=2.0 <3.0']
     * 
     * @param string $dependency 依赖字符串
     * @return array [模块名, 版本约束]
     */
    static public function parseDependency(string $dependency): array
    {
        $dependency = trim($dependency);
        
        if (empty($dependency)) {
            return ['', '*'];
        }

        // 检查是否包含 @ 符号
        if (str_contains($dependency, '@')) {
            $parts = explode('@', $dependency, 2);
            $moduleName = trim($parts[0]);
            $versionConstraint = trim($parts[1]) ?: '*';
            return [$moduleName, $versionConstraint];
        }

        // 没有 @ 符号，整个字符串就是模块名，无版本约束
        return [$dependency, '*'];
    }
}