<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Support;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Per-BE-user double-submit-token store backed by TYPO3 backend session data.
 *
 * Refactor C2: split out of DecisionController so the controller no longer
 * mixes session bookkeeping with HTTP plumbing.
 *
 * Security audit H3: consumption is serialised by a LockFactory exclusive lock
 * keyed on be_users.uid. Two concurrent submits of the same token can no
 * longer both pass the read-then-write check.
 */
final readonly class SubmitTokenStore
{
    private const SESSION_KEY = 'crowdsec_bridge.submit_tokens';
    private const TTL_SECONDS = 3600;

    public function __construct(private LockFactory $lockFactory) {}

    public function generate(?BackendUserAuthentication $user): string
    {
        if (null === $user) {
            return '';
        }

        $token = bin2hex(random_bytes(16));
        $tokens = $this->loadTokens($user);
        $tokens[$token] = time();
        $tokens = $this->garbageCollect($tokens);
        $user->setAndSaveSessionData(self::SESSION_KEY, $tokens);

        return $token;
    }

    public function consume(?BackendUserAuthentication $user, string $token): bool
    {
        if (null === $user || '' === $token) {
            return false;
        }
        $userId = (int) ($user->user['uid'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $lock = $this->lockFactory->createLocker(
            'crowdsec_submit_' . $userId,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
        );
        try {
            if (!$lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
                return false;
            }
        } catch (LockAcquireException) {
            return false;
        }

        try {
            $tokens = $this->loadTokens($user);
            if (!\array_key_exists($token, $tokens)) {
                return false;
            }
            unset($tokens[$token]);
            $user->setAndSaveSessionData(self::SESSION_KEY, $tokens);

            return true;
        } finally {
            $lock->release();
            $lock->destroy();
        }
    }

    /**
     * @return array<string, int>
     */
    private function loadTokens(BackendUserAuthentication $user): array
    {
        $raw = $user->getSessionData(self::SESSION_KEY);
        if (!\is_array($raw)) {
            return [];
        }
        $tokens = [];
        foreach ($raw as $key => $issuedAt) {
            if (\is_string($key) && \is_int($issuedAt)) {
                $tokens[$key] = $issuedAt;
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, int> $tokens
     *
     * @return array<string, int>
     */
    private function garbageCollect(array $tokens): array
    {
        $cutoff = time() - self::TTL_SECONDS;

        return array_filter($tokens, static fn(int $t): bool => $t > $cutoff);
    }
}
