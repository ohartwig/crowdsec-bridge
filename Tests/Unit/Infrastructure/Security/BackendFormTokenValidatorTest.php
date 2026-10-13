<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Security;

use Koh\CrowdsecBridge\Infrastructure\Security\BackendFormTokenValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\FormProtection\AbstractFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;

final class BackendFormTokenValidatorTest extends TestCase
{
    public function testGenerateForwardsTokenName(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $protection = $this->createMock(AbstractFormProtection::class);
        $protection->expects(self::once())
            ->method('generateToken')
            ->with(BackendFormTokenValidator::TOKEN_NAME, 'add')
            ->willReturn('token-abc');

        $factory = $this->createMock(FormProtectionFactory::class);
        $factory->expects(self::once())
            ->method('createFromRequest')
            ->with($request)
            ->willReturn($protection);

        $validator = new BackendFormTokenValidator($factory);

        self::assertSame('token-abc', $validator->generate($request, 'add'));
    }

    public function testIsValidDelegatesToProtection(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $protection = $this->createMock(AbstractFormProtection::class);
        $protection->expects(self::once())
            ->method('validateToken')
            ->with('token-abc', BackendFormTokenValidator::TOKEN_NAME, 'delete')
            ->willReturn(true);

        $factory = $this->createMock(FormProtectionFactory::class);
        $factory->method('createFromRequest')->willReturn($protection);

        $validator = new BackendFormTokenValidator($factory);

        self::assertTrue($validator->isValid($request, 'delete', 'token-abc'));
    }

    public function testEmptyTokenIsAlwaysInvalid(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $protection = $this->createMock(AbstractFormProtection::class);
        $protection->expects(self::never())->method('validateToken');

        $factory = $this->createMock(FormProtectionFactory::class);
        $factory->method('createFromRequest')->willReturn($protection);

        $validator = new BackendFormTokenValidator($factory);

        self::assertFalse($validator->isValid($request, 'add', ''));
    }
}
