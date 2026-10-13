<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

final class LapiAuthenticationException extends \RuntimeException
{
    /**
     * A refused credential and an absent one need different actions from the
     * operator, and the status panel is where they find out which. Carrying the
     * distinction as a code rather than reading it back out of the message
     * keeps the two from being told apart by string matching.
     *
     * @param 'auth_failed'|'no_machine_account' $reasonCode
     */
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function invalidApiKey(): self
    {
        return new self('LAPI rejected the provided API key (HTTP 401)', 'auth_failed');
    }

    /**
     * Not "authentication failed" but "there is nothing to authenticate with".
     * The two need different answers from the operator — a wrong password is
     * fixed in the secret store, a missing machine account is created with
     * `cscli machines add` first — so they must not share a message.
     */
    public static function machineAccountMissing(): self
    {
        return new self(
            'No CrowdSec machine account configured. /v1/heartbeat, /v1/alerts and DELETE /v1/decisions/{id} '
            . 'sit behind LAPI\'s JWT middleware and cannot be reached with the bouncer key. Provide '
            . '/run/secrets/crowdsec_machine_id + /run/secrets/crowdsec_machine_password, or '
            . '$CROWDSEC_LAPI_MACHINE_ID + $CROWDSEC_LAPI_MACHINE_PASSWORD.',
            'no_machine_account',
        );
    }

    /**
     * The account logged in and the route still said no. Distinct from a
     * refused login, because the fix is different: the machine exists but is
     * not allowed here — which on a stock CrowdSec means the route is not the
     * watcher route it was taken for.
     */
    public static function machineTokenRejected(string $path): self
    {
        return new self(
            \sprintf('LAPI refused the machine token on %s (HTTP 401). The login succeeded, so the account exists — the route did not accept it.', $path),
            'auth_failed',
        );
    }

    public static function machineLoginRejected(int $status): self
    {
        return new self(\sprintf('LAPI refused the machine account at POST /v1/watchers/login (HTTP %d)', $status), 'auth_failed');
    }
}
