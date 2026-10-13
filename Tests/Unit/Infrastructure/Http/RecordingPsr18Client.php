<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps every request it was
 * given.
 *
 * The assertions in this directory are about what goes out on the wire — which
 * header carries the credential, whether a refused request is tried again —
 * and a mock whose withHeader() returns itself cannot answer either question.
 */
final class RecordingPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public static function answering(int ...$statuses): self
    {
        return new self(...array_map(static fn(int $status): Response => new Response($status), $statuses));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->responses);
        if (null === $next) {
            throw new \RuntimeException('The client was asked for more responses than the test queued.');
        }

        return $next;
    }
}
