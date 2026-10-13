<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every template name the controller hands to render() must exist under
 * Resources/Private/Templates, folder included.
 *
 * The controller is a plain backend controller, not Extbase: ModuleTemplate
 * resolves the name relative to the template root with no controller folder
 * of its own. render($request, 'List') looked for Templates/List.html and
 * Templates/Default/List.html while the file lay in Templates/Decision/ --
 * the module answered 500 on every visit (2026-10-03), and the guard tests
 * never got as far as rendering, because ModuleTemplateFactory is final.
 */
final class DecisionControllerTemplatesTest extends TestCase
{
    #[Test]
    public function everyRenderedTemplateExists(): void
    {
        $root = \dirname(__DIR__, 5);
        $source = (string) file_get_contents($root . '/Classes/Presentation/Backend/Controller/DecisionController.php');
        preg_match_all("~->render\\(\\s*\\\$request\\s*,\\s*'([^']+)'~", $source, $matches);

        self::assertNotEmpty($matches[1], 'no render() calls found -- the pattern no longer matches the controller');
        foreach (array_unique($matches[1]) as $template) {
            self::assertFileExists(
                $root . '/Resources/Private/Templates/' . $template . '.html',
                \sprintf('render(..., \'%s\') has no template file', $template),
            );
        }
    }

    /**
     * `f:uri.module` does not exist in TYPO3 14: every visit after the template
     * fix above answered 500 with "The ViewHelper <f:uri.module> could not be
     * resolved" (2026-10-03). Links go through be:moduleLink, by the route NAME
     * of the sub-route -- an `action` argument on the default route is ignored.
     */
    #[Test]
    public function everyModuleLinkNamesARegisteredRoute(): void
    {
        $root = \dirname(__DIR__, 5);
        $modules = require $root . '/Configuration/Backend/Modules.php';
        $routes = [];
        foreach ($modules as $identifier => $module) {
            foreach (array_keys($module['routes'] ?? []) as $key) {
                $routes[] = '_default' === $key ? $identifier : $identifier . '.' . $key;
            }
        }

        $links = 0;
        foreach ($this->templates($root) as $file => $html) {
            self::assertStringNotContainsString('f:uri.module', $html, $file . ' uses f:uri.module, which TYPO3 14 does not have');
            preg_match_all("~be:moduleLink\\(route: '([^']+)'~", $html, $matches);
            foreach ($matches[1] as $route) {
                ++$links;
                self::assertContains($route, $routes, \sprintf('%s links to unknown route %s', $file, $route));
            }
            if ([] !== $matches[1]) {
                self::assertStringContainsString('xmlns:be="http://typo3.org/ns/TYPO3/CMS/Backend/ViewHelpers"', $html, $file . ' uses be: without declaring it');
            }
        }
        self::assertGreaterThan(0, $links, 'no be:moduleLink found -- the pattern no longer matches the templates');
    }

    /**
     * A GET form drops the query string of its action URL, and with it the
     * route token every backend route checks. The token travels as a field.
     */
    #[Test]
    public function everyGetFormCarriesTheRouteToken(): void
    {
        $forms = 0;
        foreach ($this->templates(\dirname(__DIR__, 5)) as $file => $html) {
            preg_match_all('~<form[^>]*method="get"[^>]*>(.*?)</form>~s', $html, $matches);
            foreach ($matches[1] as $body) {
                ++$forms;
                self::assertMatchesRegularExpression('~<input type="hidden" name="token" value="\{routeTokens\.\w+\}"/>~', $body, $file . ': GET form without route token');
            }
        }
        self::assertGreaterThan(0, $forms);
    }

    /**
     * @return array<string, string>
     */
    private function templates(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Resources/Private', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('html' === $file->getExtension()) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
