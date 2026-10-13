<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Presentation\Backend\Support\SubmitTokenStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * This is the double-submit guard in front of decision changes, so the property
 * that matters is single use: a token must work exactly once, and a second
 * submit of the same token — concurrent or not — has to be refused.
 *
 * The lock exists precisely because read-then-write is not atomic. If it cannot
 * be taken, refusing is the only safe answer; treating a failed lock as "go
 * ahead" would reopen the race it was added to close.
 */
final class SubmitTokenStoreTest extends TestCase
{
    private const SESSION_KEY = 'crowdsec_bridge.submit_tokens';

    private LockFactory&MockObject $lockFactory;
    private LockingStrategyInterface&MockObject $lock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lock = $this->createMock(LockingStrategyInterface::class);
        $this->lock->method('acquire')->willReturn(true);

        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lockFactory->method('createLocker')->willReturn($this->lock);
    }

    private function subject(): SubmitTokenStore
    {
        return new SubmitTokenStore($this->lockFactory);
    }

    /**
     * A backend user whose session data behaves like the real thing: what is
     * written is what comes back.
     *
     * Deliberately typed as mixed values: session data is untrusted input, and
     * one of the tests puts a malformed entry in there on purpose.
     *
     * @param array<string, mixed> $initialTokens
     */
    private function user(array $initialTokens = [], int $uid = 7): BackendUserAuthentication&MockObject
    {
        $session = [self::SESSION_KEY => $initialTokens];

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid];
        // Both closures bind $session by reference — a read has to see what an
        // earlier write put there, otherwise "consume once" cannot be observed.
        $user->method('getSessionData')->willReturnCallback(
            static function (string $key) use (&$session): mixed {
                return $session[$key] ?? null;
            },
        );
        $user->method('setAndSaveSessionData')->willReturnCallback(
            static function (string $key, mixed $value) use (&$session): void {
                $session[$key] = $value;
            },
        );

        return $user;
    }

    #[Test]
    public function aGeneratedTokenCanBeConsumedOnce(): void
    {
        $subject = $this->subject();
        $user = $this->user();

        $token = $subject->generate($user);

        self::assertNotSame('', $token);
        self::assertTrue($subject->consume($user, $token), 'the freshly issued token has to be accepted');
        self::assertFalse($subject->consume($user, $token), 'replaying the same token must be refused');
    }

    #[Test]
    public function tokensAreUniquePerCall(): void
    {
        $subject = $this->subject();
        $user = $this->user();

        self::assertNotSame($subject->generate($user), $subject->generate($user));
    }

    #[Test]
    public function anUnknownTokenIsRefused(): void
    {
        self::assertFalse($this->subject()->consume($this->user(), 'not-a-token'));
    }

    #[Test]
    public function anEmptyTokenIsRefused(): void
    {
        $this->lockFactory->expects(self::never())->method('createLocker');

        self::assertFalse($this->subject()->consume($this->user(), ''));
    }

    /**
     * Without a backend user there is nothing to bind a token to. Generating
     * must not hand out a usable value, and consuming must not succeed.
     */
    #[Test]
    public function withoutABackendUserNothingIsIssuedOrAccepted(): void
    {
        $subject = $this->subject();

        self::assertSame('', $subject->generate(null));
        self::assertFalse($subject->consume(null, 'anything'));
    }

    /**
     * The lock is keyed on the user uid, so a session without a usable uid
     * cannot be serialised and has to be rejected rather than silently sharing
     * a lock with another user.
     */
    #[Test]
    public function aUserWithoutAUsableUidIsRefused(): void
    {
        $this->lockFactory->expects(self::never())->method('createLocker');

        $subject = $this->subject();
        $user = $this->user(uid: 0);
        $token = $subject->generate($user);

        self::assertFalse($subject->consume($user, $token));
    }

    /**
     * A lock that cannot be taken means another submit is in flight. Refusing
     * is the safe answer — proceeding would be exactly the double submit the
     * lock is there to prevent.
     */
    #[Test]
    public function aTokenIsRefusedWhenTheLockCannotBeAcquired(): void
    {
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($lock);

        $subject = new SubmitTokenStore($lockFactory);
        $user = $this->user();
        $token = new SubmitTokenStore($this->lockFactory)->generate($user);

        self::assertFalse($subject->consume($user, $token));
    }

    /**
     * A lock backend that raises instead of returning false must not turn into
     * an exception in the controller either — same situation, same answer.
     *
     * Only the acquire() call is guarded; a locker that cannot even be built is
     * deliberately left to propagate.
     */
    #[Test]
    public function aLockThatRaisesWhileAcquiringIsTreatedAsRefusal(): void
    {
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willThrowException(new LockAcquireException('busy'));

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($lock);

        $subject = new SubmitTokenStore($lockFactory);
        $user = $this->user();
        $token = new SubmitTokenStore($this->lockFactory)->generate($user);

        self::assertFalse($subject->consume($user, $token));
    }

    /**
     * The lock has to come back even when the token turns out to be unknown —
     * otherwise one bad submit would wedge every later one for that user.
     */
    #[Test]
    public function theLockIsAlwaysReleased(): void
    {
        $this->lock->expects(self::once())->method('release');
        $this->lock->expects(self::once())->method('destroy');

        $this->subject()->consume($this->user(), 'unknown-token');
    }

    /**
     * Tokens expire after an hour. An entry older than that must not be
     * carried forward when a new token is issued.
     */
    #[Test]
    public function expiredTokensAreDroppedWhenANewOneIsIssued(): void
    {
        $subject = $this->subject();
        $stale = 'stale-token';
        $user = $this->user([$stale => time() - 3601]);

        $subject->generate($user);

        self::assertFalse($subject->consume($user, $stale), 'a token past its TTL must no longer be accepted');
    }

    #[Test]
    public function aTokenInsideTheTtlSurvivesANewIssue(): void
    {
        $subject = $this->subject();
        $fresh = 'fresh-token';
        $user = $this->user([$fresh => time() - 60]);

        $subject->generate($user);

        self::assertTrue($subject->consume($user, $fresh));
    }

    /**
     * Session data is untrusted input: it can hold anything after a format
     * change or manual edit. Malformed entries have to be ignored rather than
     * accepted as tokens.
     */
    #[Test]
    public function malformedSessionEntriesAreIgnored(): void
    {
        $subject = $this->subject();
        $user = $this->user(['valid' => time(), 'broken' => 'not-a-timestamp']);

        self::assertFalse($subject->consume($user, 'broken'));
        self::assertTrue($subject->consume($this->user(['valid' => time()]), 'valid'));
    }

    #[Test]
    public function sessionDataThatIsNotAnArrayIsTreatedAsEmpty(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => 7];
        $user->method('getSessionData')->willReturn('not-an-array');

        self::assertFalse($this->subject()->consume($user, 'whatever'));
    }
}
