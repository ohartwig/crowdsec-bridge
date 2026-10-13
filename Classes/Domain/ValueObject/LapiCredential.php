<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

/**
 * Which identity a LAPI request speaks as.
 *
 * CrowdSec puts its routes behind two different middlewares and the split is
 * not negotiable from the client side:
 *
 *  - **BouncerApiKey** — the `X-Api-Key` header, checked against the registered
 *    bouncers. It opens `GET /v1/decisions` and `GET /v1/decisions/stream`, and
 *    nothing else the bridge calls.
 *  - **MachineToken** — a JWT obtained from `POST /v1/watchers/login` with a
 *    machine account's credentials. It opens `/v1/heartbeat`, `/v1/alerts`,
 *    `/v1/alerts/{id}` and `DELETE /v1/decisions/{id}`.
 *
 * Sending the wrong one is answered with 401, not with a partial result, so a
 * route and its credential belong together. Tests/Contract/LapiAuthSchemeContractTest
 * records the split against a real CrowdSec.
 */
enum LapiCredential
{
    case BouncerApiKey;
    case MachineToken;
}
