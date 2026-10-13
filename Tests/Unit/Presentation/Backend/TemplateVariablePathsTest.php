<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every segment of a variable path in the templates must be something Fluid
 * can read.
 *
 * Fluid resolves `{a.b}` through getB(), isB(), hasB() and then the property
 * b - never through a method called b(). Three ways that went wrong in this
 * module on 2026-10-03, all invisible until a screen rendered the line:
 *
 * - `pagination.nextPage` reached hasNextPage(int), which needs an argument:
 *   500 as soon as the list had a second page;
 * - `pagination.previousPage` reached hasPreviousPage() and linked to "1" as a
 *   boolean;
 * - `listView.hasFilter` and `pagination.hasPreviousPage` resolved to nothing
 *   at all, so the reset link and the link back never showed.
 *
 * The render test catches what its data reaches; this one reads every path in
 * every template, reached or not.
 */
final class TemplateVariablePathsTest extends TestCase
{
    #[Test]
    public function everyPathSegmentIsReadableByFluid(): void
    {
        $root = \dirname(__DIR__, 4);
        $classes = $this->classes($root . '/Classes');
        $problems = [];
        $checked = 0;
        foreach ($this->segments($root . '/Resources/Private') as $segment => $uses) {
            foreach ($classes as $class) {
                $problem = $this->problem(new \ReflectionClass($class), $segment);
                if (null !== $problem) {
                    $problems[] = \sprintf('%s (%s)', $problem, implode(', ', array_unique($uses)));
                }
            }
            ++$checked;
        }

        self::assertGreaterThan(20, $checked, 'too few paths found - the pattern no longer matches the templates');
        self::assertSame([], $problems);
    }

    /**
     * How Fluid would resolve $segment on $class, as a problem, or null when it
     * resolves cleanly or the class has no member of that name at all.
     *
     * @param \ReflectionClass<object> $class
     */
    private function problem(\ReflectionClass $class, string $segment): ?string
    {
        $upper = ucfirst($segment);
        $ownMember = $class->hasMethod($segment) || $class->hasProperty($segment);
        foreach (['get', 'is', 'has'] as $prefix) {
            if (!$class->hasMethod($prefix . $upper)) {
                continue;
            }
            $method = $class->getMethod($prefix . $upper);
            if ($method->getNumberOfRequiredParameters() > 0) {
                return \sprintf('%s::%s() needs an argument', $class->getShortName(), $method->getName());
            }
            if ('get' !== $prefix && $ownMember && !$class->hasProperty($segment)) {
                return \sprintf('%s::%s() answers for %s()', $class->getShortName(), $method->getName(), $segment);
            }

            return null;
        }
        if ($class->hasProperty($segment)) {
            return null;
        }
        if ($class->hasMethod($segment)) {
            return \sprintf('%s::%s() is never called by Fluid', $class->getShortName(), $segment);
        }

        return null;
    }

    /**
     * Path segments after the first, from variable expressions only: the
     * content of {...} with quoted strings removed, so translation keys and
     * ViewHelper names (f:format.date) do not count.
     *
     * @return array<string, list<string>>
     */
    private function segments(string $dir): array
    {
        $segments = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('html' !== $file->getExtension()) {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            preg_match_all('/\{([^{}]*)\}/', $html, $blocks);
            foreach ($blocks[1] as $block) {
                $block = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"[^\"]*\"/", '', $block);
                preg_match_all('/(?<![:\w.])([a-zA-Z]\w*(?:\.[a-zA-Z]\w*)+)/', $block, $paths);
                foreach ($paths[1] as $path) {
                    $parts = explode('.', $path);
                    array_shift($parts);
                    foreach ($parts as $part) {
                        $segments[$part][] = $file->getFilename() . ':' . $path;
                    }
                }
            }
        }

        return $segments;
    }

    /**
     * @return list<class-string>
     */
    private function classes(string $dir): array
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (1 !== preg_match('/^namespace ([^;]+);/m', $source, $ns) || 1 !== preg_match('/^(?:final |abstract |readonly )*(?:class|enum) (\w+)/m', $source, $name)) {
                continue;
            }
            $class = $ns[1] . '\\' . $name[1];
            if (class_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
