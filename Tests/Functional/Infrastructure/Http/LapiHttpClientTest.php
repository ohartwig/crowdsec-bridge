<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Functional\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClient;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Functional tests against a hand-rolled PSR-18 mock pair. Constitution forbids hitting a
 * real CrowdSec container in CI.
 */
final class LapiHttpClientTest extends TestCase
{
    public function testGetReturnsDecodedJson(): void
    {
        $client = $this->makeClient(200, '[{"id":1,"scope":"Ip","type":"ban","value":"1.1.1.1","duration":"1h","origin":"x","scenario":"x"}]');

        $result = $client->get('/v1/decisions');

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
    }

    public function testGetMapsTimeoutToLapiUnavailable(): void
    {
        $client = $this->makeClient(0, '', throwOnSend: true);

        $this->expectException(LapiUnavailableException::class);
        $client->get('/v1/decisions');
    }

    public function testGetMaps401ToLapiAuthentication(): void
    {
        $client = $this->makeClient(401, '{"message":"unauthorized"}');

        $this->expectException(LapiAuthenticationException::class);
        $client->get('/v1/decisions');
    }

    public function testGetMaps500ToLapiUnavailable(): void
    {
        $client = $this->makeClient(500, '');

        $this->expectException(LapiUnavailableException::class);
        $client->get('/v1/decisions');
    }

    public function testGetMapsMalformedJsonToInvalidResponse(): void
    {
        $client = $this->makeClient(200, '{not-json');

        $this->expectException(LapiUnavailableException::class);
        $this->expectExceptionMessageMatches('/malformed JSON/');
        $client->get('/v1/decisions');
    }

    private function makeClient(int $status, string $body, bool $throwOnSend = false): LapiHttpClient
    {
        $resolver = $this->keyResolver();
        $factory = $this->requestFactory();
        $http = new class ($status, $body, $throwOnSend) implements ClientInterface {
            public function __construct(
                private readonly int $status,
                private readonly string $body,
                private readonly bool $throwOnSend,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->throwOnSend) {
                    throw new class extends \RuntimeException implements ClientExceptionInterface {};
                }

                return new class ($this->status, $this->body) implements ResponseInterface {
                    public function __construct(private int $status, private string $body) {}

                    public function getProtocolVersion(): string
                    {
                        return '1.1';
                    }

                    public function withProtocolVersion(string $version): self
                    {
                        return $this;
                    }

                    public function getHeaders(): array
                    {
                        return [];
                    }

                    public function hasHeader(string $name): bool
                    {
                        return false;
                    }

                    public function getHeader(string $name): array
                    {
                        return [];
                    }

                    public function getHeaderLine(string $name): string
                    {
                        return '';
                    }

                    public function withHeader(string $name, $value): self
                    {
                        return $this;
                    }

                    public function withAddedHeader(string $name, $value): self
                    {
                        return $this;
                    }

                    public function withoutHeader(string $name): self
                    {
                        return $this;
                    }

                    public function getBody(): StreamInterface
                    {
                        return new class ($this->body) implements StreamInterface {
                            public function __construct(private string $contents) {}

                            public function __toString(): string
                            {
                                return $this->contents;
                            }

                            public function close(): void {}

                            public function detach()
                            {
                                return null;
                            }

                            public function getSize(): int
                            {
                                return \strlen($this->contents);
                            }

                            public function tell(): int
                            {
                                return 0;
                            }

                            public function eof(): bool
                            {
                                return true;
                            }

                            public function isSeekable(): bool
                            {
                                return false;
                            }

                            public function seek(int $offset, int $whence = \SEEK_SET): void {}

                            public function rewind(): void {}

                            public function isWritable(): bool
                            {
                                return false;
                            }

                            public function write(string $string): int
                            {
                                return 0;
                            }

                            public function isReadable(): bool
                            {
                                return true;
                            }

                            public function read(int $length): string
                            {
                                return $this->contents;
                            }

                            public function getContents(): string
                            {
                                return $this->contents;
                            }

                            public function getMetadata(?string $key = null)
                            {
                                return null;
                            }
                        };
                    }

                    public function withBody(StreamInterface $body): self
                    {
                        return $this;
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }

                    public function withStatus(int $code, string $reasonPhrase = ''): self
                    {
                        return $this;
                    }

                    public function getReasonPhrase(): string
                    {
                        return '';
                    }
                };
            }
        };

        return new LapiHttpClient($http, $factory, $this->streamFactory(), $resolver, 'http://lapi-mock:8080');
    }

    private function streamFactory(): \Psr\Http\Message\StreamFactoryInterface
    {
        return new class implements \Psr\Http\Message\StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return $this->wrap($content);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return $this->wrap((string) file_get_contents($filename));
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return $this->wrap((string) stream_get_contents($resource));
            }

            private function wrap(string $content): StreamInterface
            {
                return new class ($content) implements StreamInterface {
                    public function __construct(private string $contents) {}

                    public function __toString(): string
                    {
                        return $this->contents;
                    }

                    public function close(): void {}

                    public function detach()
                    {
                        return null;
                    }

                    public function getSize(): int
                    {
                        return \strlen($this->contents);
                    }

                    public function tell(): int
                    {
                        return 0;
                    }

                    public function eof(): bool
                    {
                        return true;
                    }

                    public function isSeekable(): bool
                    {
                        return false;
                    }

                    public function seek(int $offset, int $whence = \SEEK_SET): void {}

                    public function rewind(): void {}

                    public function isWritable(): bool
                    {
                        return true;
                    }

                    public function write(string $string): int
                    {
                        $this->contents .= $string;

                        return \strlen($string);
                    }

                    public function isReadable(): bool
                    {
                        return true;
                    }

                    public function read(int $length): string
                    {
                        return $this->contents;
                    }

                    public function getContents(): string
                    {
                        return $this->contents;
                    }

                    public function getMetadata(?string $key = null)
                    {
                        return null;
                    }
                };
            }
        };
    }

    private function keyResolver(): LapiKeyResolver
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apikey');
        file_put_contents($tmp, 'test-key');

        return new LapiKeyResolver($tmp);
    }

    private function requestFactory(): RequestFactoryInterface
    {
        return new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new class ($method, (string) $uri) implements RequestInterface {
                    /** @var array<string, array<string>> */
                    private array $headers = [];

                    public function __construct(private string $method, private string $uri) {}

                    public function getProtocolVersion(): string
                    {
                        return '1.1';
                    }

                    public function withProtocolVersion(string $version): self
                    {
                        return $this;
                    }

                    public function getHeaders(): array
                    {
                        return $this->headers;
                    }

                    public function hasHeader(string $name): bool
                    {
                        return isset($this->headers[$name]);
                    }

                    public function getHeader(string $name): array
                    {
                        return $this->headers[$name] ?? [];
                    }

                    public function getHeaderLine(string $name): string
                    {
                        return implode(', ', $this->getHeader($name));
                    }

                    public function withHeader(string $name, $value): self
                    {
                        $clone = clone $this;
                        $clone->headers[$name] = (array) $value;

                        return $clone;
                    }

                    public function withAddedHeader(string $name, $value): self
                    {
                        return $this->withHeader($name, $value);
                    }

                    public function withoutHeader(string $name): self
                    {
                        $clone = clone $this;
                        unset($clone->headers[$name]);

                        return $clone;
                    }

                    public function getBody(): StreamInterface
                    {
                        return new class implements StreamInterface {
                            public function __toString(): string
                            {
                                return '';
                            }

                            public function close(): void {}

                            public function detach()
                            {
                                return null;
                            }

                            public function getSize(): int
                            {
                                return 0;
                            }

                            public function tell(): int
                            {
                                return 0;
                            }

                            public function eof(): bool
                            {
                                return true;
                            }

                            public function isSeekable(): bool
                            {
                                return false;
                            }

                            public function seek(int $offset, int $whence = \SEEK_SET): void {}

                            public function rewind(): void {}

                            public function isWritable(): bool
                            {
                                return false;
                            }

                            public function write(string $string): int
                            {
                                return 0;
                            }

                            public function isReadable(): bool
                            {
                                return false;
                            }

                            public function read(int $length): string
                            {
                                return '';
                            }

                            public function getContents(): string
                            {
                                return '';
                            }

                            public function getMetadata(?string $key = null)
                            {
                                return null;
                            }
                        };
                    }

                    public function withBody(StreamInterface $body): self
                    {
                        return $this;
                    }

                    public function getRequestTarget(): string
                    {
                        return $this->uri;
                    }

                    public function withRequestTarget(string $requestTarget): self
                    {
                        return $this;
                    }

                    public function getMethod(): string
                    {
                        return $this->method;
                    }

                    public function withMethod(string $method): self
                    {
                        $clone = clone $this;
                        $clone->method = $method;

                        return $clone;
                    }

                    public function getUri(): \Psr\Http\Message\UriInterface
                    {
                        return new class ($this->uri) implements \Psr\Http\Message\UriInterface {
                            public function __construct(private string $uri) {}

                            public function __toString(): string
                            {
                                return $this->uri;
                            }

                            public function getScheme(): string
                            {
                                return 'http';
                            }

                            public function getAuthority(): string
                            {
                                return '';
                            }

                            public function getUserInfo(): string
                            {
                                return '';
                            }

                            public function getHost(): string
                            {
                                return '';
                            }

                            public function getPort(): ?int
                            {
                                return null;
                            }

                            public function getPath(): string
                            {
                                return $this->uri;
                            }

                            public function getQuery(): string
                            {
                                return '';
                            }

                            public function getFragment(): string
                            {
                                return '';
                            }

                            public function withScheme(string $scheme): self
                            {
                                return $this;
                            }

                            public function withUserInfo(string $user, ?string $password = null): self
                            {
                                return $this;
                            }

                            public function withHost(string $host): self
                            {
                                return $this;
                            }

                            public function withPort(?int $port): self
                            {
                                return $this;
                            }

                            public function withPath(string $path): self
                            {
                                return $this;
                            }

                            public function withQuery(string $query): self
                            {
                                return $this;
                            }

                            public function withFragment(string $fragment): self
                            {
                                return $this;
                            }
                        };
                    }

                    public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): self
                    {
                        return $this;
                    }
                };
            }
        };
    }
}
