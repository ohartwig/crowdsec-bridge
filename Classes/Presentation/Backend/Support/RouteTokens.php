<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Support;

use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The backend route token per route, for forms submitted with GET.
 *
 * Every backend route checks a `token` request parameter (RouteDispatcher::
 * assertRequestToken), and UriBuilder puts it into the query string of the
 * URL it builds. A browser drops that query string when it submits a GET
 * form to the URL, so the quick check and both filter forms arrived without
 * a token and failed. The forms carry the token as a hidden field instead,
 * taken from the same URL the action attribute shows.
 */
final readonly class RouteTokens
{
    public function __construct(private UriBuilder $uriBuilder) {}

    /**
     * @param array<string, string> $routes key used in the template => route name
     *
     * @return array<string, string>
     */
    public function for(array $routes): array
    {
        $tokens = [];
        foreach ($routes as $key => $route) {
            parse_str($this->uriBuilder->buildUriFromRoute($route)->getQuery(), $query);
            $tokens[$key] = \is_string($query['token'] ?? null) ? $query['token'] : '';
        }

        return $tokens;
    }
}
