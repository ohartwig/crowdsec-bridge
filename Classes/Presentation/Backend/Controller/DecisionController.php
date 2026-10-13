<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Controller;

use Koh\CrowdsecBridge\Application\Command\AddDecisionCommand;
use Koh\CrowdsecBridge\Application\Command\AddDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommand;
use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Contract\FormTokenValidatorInterface;
use Koh\CrowdsecBridge\Application\Query\CheckIpQuery;
use Koh\CrowdsecBridge\Application\Query\CheckIpQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQuery;
use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQuery;
use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListAlertsQuery;
use Koh\CrowdsecBridge\Application\Query\ListAlertsQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListDecisionsQuery;
use Koh\CrowdsecBridge\Application\Query\ListDecisionsQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\OperatorPermissionsResolverInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\AlertNotFoundException;
use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionDraftException;
use Koh\CrowdsecBridge\Domain\Exception\InvalidIpAddressException;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use Koh\CrowdsecBridge\Presentation\Backend\Support\AlertsListReturnUrl;
use Koh\CrowdsecBridge\Presentation\Backend\Support\FlashMessenger;
use Koh\CrowdsecBridge\Presentation\Backend\Support\RouteTokens;
use Koh\CrowdsecBridge\Presentation\Backend\Support\SubmitTokenStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module controller for the System → CrowdSec module.
 * Implements list/quick-check (Feature 001) and add/delete with audit (Feature 002).
 */
final readonly class DecisionController
{
    private const MODULE_IDENTIFIER = 'system_crowdsec';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ListDecisionsQueryHandler $listHandler,
        private GetLapiStatusQueryHandler $statusHandler,
        private CheckIpQueryHandler $checkHandler,
        private AddDecisionCommandHandler $addHandler,
        private DeleteDecisionCommandHandler $deleteHandler,
        private ListAlertsQueryHandler $alertListHandler,
        private GetAlertDetailQueryHandler $alertDetailHandler,
        private FormTokenValidatorInterface $formTokenValidator,
        private WriteAuditLoggerInterface $writeAudit,
        private OperatorPermissionsResolverInterface $permissionsResolver,
        private DecisionRepositoryInterface $repository,
        private FlashMessenger $flashMessenger,
        private SubmitTokenStore $submitTokens,
        private UriBuilder $uriBuilder,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        if (!$permissions->canRead) {
            return $this->forbidden();
        }

        $parsed = $request->getQueryParams();
        $query = new ListDecisionsQuery(
            scope: $this->parseScope($parsed['scope'] ?? null),
            type: $this->parseType($parsed['type'] ?? null),
            origin: $this->parseOrigin($parsed['origin'] ?? null),
            page: $this->parsePage($parsed['page'] ?? null),
        );

        $status = $this->statusHandler->handle(new GetLapiStatusQuery());
        // The list is fetched regardless of what the status panel says. The
        // heartbeat sits behind LAPI's JWT middleware while the decision list is
        // the one route a bouncer key opens, so gating the list on the heartbeat
        // made an installation without a machine account show an empty module
        // instead of the one screen it was entitled to. safeList() already turns
        // a genuinely unreachable LAPI into the same null.
        $listView = $this->safeList($query);

        return $this->render($request, 'List', [
            'permissions' => $permissions,
            'status' => $status,
            'listView' => $listView,
            'deleteFormToken' => $this->deleteTokenFor($request, $permissions),
            'ipCheckResult' => null,
            'ipCheckInputValue' => '',
            'ipCheckError' => null,
            'activeTab' => 'decisions',
        ]);
    }

    public function checkIpAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        if (!$permissions->canRead) {
            return $this->forbidden();
        }

        $params = $request->getQueryParams();
        $ipInput = (string) ($params['ip'] ?? '');
        $status = $this->statusHandler->handle(new GetLapiStatusQuery());

        $checkResult = null;
        $checkError = null;
        $backendUser = $this->backendUser();

        if ('' === $ipInput) {
            $checkError = 'invalid_ip';
        } else {
            try {
                $checkResult = $this->checkHandler->handle(new CheckIpQuery(
                    ipString: $ipInput,
                    backendUserId: (int) ($backendUser['uid'] ?? 0),
                    backendUserName: (string) ($backendUser['username'] ?? 'unknown'),
                ));
            } catch (InvalidIpAddressException) {
                $checkError = 'invalid_ip';
            } catch (LapiAuthenticationException|LapiUnavailableException) {
                $checkError = 'lapi_error';
            }
        }

        $listView = $this->safeList(new ListDecisionsQuery());

        return $this->render($request, 'List', [
            'permissions' => $permissions,
            'status' => $status,
            'listView' => $listView,
            'deleteFormToken' => $this->deleteTokenFor($request, $permissions),
            'ipCheckResult' => $checkResult,
            'ipCheckInputValue' => $ipInput,
            'ipCheckError' => $checkError,
            'activeTab' => 'decisions',
        ]);
    }

    public function alertListAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        if (!$permissions->canRead) {
            return $this->forbidden();
        }

        $parsed = $request->getQueryParams();
        $query = new ListAlertsQuery(
            scope: $this->parseScope($parsed['scope'] ?? null),
            scenarioContains: $this->parseScenarioContains($parsed['scenario'] ?? null),
            page: $this->parsePage($parsed['page'] ?? null),
        );

        $status = $this->statusHandler->handle(new GetLapiStatusQuery());
        $alertListView = null;
        if ($status->isReachable) {
            try {
                $alertListView = $this->alertListHandler->handle($query);
            } catch (LapiAuthenticationException|LapiUnavailableException) {
                $alertListView = null;
            }
        }

        return $this->render($request, 'Alert/List', [
            'permissions' => $permissions,
            'status' => $status,
            'alertListView' => $alertListView,
            'activeTab' => 'alerts',
        ]);
    }

    public function alertDetailAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        if (!$permissions->canRead) {
            return $this->forbidden();
        }

        $params = $request->getQueryParams();
        $rawId = (string) ($params['id'] ?? '');
        if (!ctype_digit($rawId) || (int) $rawId <= 0) {
            return $this->forbidden();
        }

        $status = $this->statusHandler->handle(new GetLapiStatusQuery());
        $notFound = false;
        $alertDetailView = null;

        try {
            $alertDetailView = $this->alertDetailHandler->handle(new GetAlertDetailQuery(new AlertId((int) $rawId)));
        } catch (AlertNotFoundException) {
            $notFound = true;
        } catch (LapiAuthenticationException|LapiUnavailableException) {
            $alertDetailView = null;
        }

        return $this->render($request, 'Alert/Detail', [
            'permissions' => $permissions,
            'status' => $status,
            'alertDetailView' => $alertDetailView,
            'notFound' => $notFound,
            'returnToUrl' => new AlertsListReturnUrl($this->uriBuilder)->build($params),
            'activeTab' => 'alerts',
        ]);
    }

    private function parseScenarioContains(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        if (!$permissions->canWrite) {
            $this->auditDeniedWrite(DecisionWriteAction::WriteDenied, 'no_operator_role');

            return $this->forbidden();
        }

        return $this->renderNewForm($request, $permissions, [], null);
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        if (!$permissions->canWrite) {
            $this->auditDeniedWrite(DecisionWriteAction::WriteDenied, 'no_operator_role');

            return $this->forbidden();
        }

        $formToken = (string) ($body['formToken'] ?? '');
        if (!$this->formTokenValidator->isValid($request, 'add', $formToken)) {
            $this->auditDeniedWrite(DecisionWriteAction::CsrfDenied, 'csrf_token_invalid');
            $this->flashMessenger->enqueue('flash.error.csrf', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToList();
        }

        $submitToken = (string) ($body['submitToken'] ?? '');
        if (!$this->submitTokens->consume($GLOBALS['BE_USER'] ?? null, $submitToken)) {
            $this->auditDeniedWrite(DecisionWriteAction::DuplicateSubmit, 'duplicate_submit');
            $this->flashMessenger->enqueue('flash.error.duplicate_submit', ContextualFeedbackSeverity::WARNING);

            return $this->redirectToList();
        }

        try {
            $draft = $this->buildDraft($body);
        } catch (InvalidDecisionDraftException $caught) {
            return $this->renderNewForm($request, $permissions, $body, $caught);
        }

        $confirmDuplicate = '1' === (string) ($body['confirmDuplicate'] ?? '');
        if (!$confirmDuplicate && $this->hasExistingDecisionsForValue($draft)) {
            $body['_warning'] = 'duplicate_value';

            return $this->renderNewForm($request, $permissions, $body, null, true);
        }

        $backendUser = $this->backendUser();

        try {
            $result = $this->addHandler->handle(new AddDecisionCommand(
                draft: $draft,
                backendUserId: (int) ($backendUser['uid'] ?? 0),
                backendUserName: (string) ($backendUser['username'] ?? 'unknown'),
                permissions: $permissions,
                submitTokenAlreadyConsumed: false,
            ));
        } catch (ForbiddenWriteException $caught) {
            $this->flashMessenger->enqueue('flash.error.forbidden', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToList();
        } catch (LapiAuthenticationException|LapiUnavailableException) {
            $this->flashMessenger->enqueue('flash.add.failed', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToList();
        }

        // The alert id, named as such: LAPI creates a decision only as part of an
        // alert and never reports the decision's own id back.
        $this->flashMessenger->enqueue('flash.add.success', ContextualFeedbackSeverity::OK, [
            $result->alertId->toInt(),
        ]);

        return $this->redirectToList();
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = $this->permissionsResolver->resolve();
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        if (!$permissions->canWrite && !$permissions->canDeleteForeign) {
            $this->auditDeniedWrite(DecisionWriteAction::WriteDenied, 'no_operator_role');

            return $this->forbidden();
        }

        $formToken = (string) ($body['formToken'] ?? '');
        if (!$this->formTokenValidator->isValid($request, 'delete', $formToken)) {
            $this->auditDeniedWrite(DecisionWriteAction::CsrfDenied, 'csrf_token_invalid');
            $this->flashMessenger->enqueue('flash.error.csrf', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToList();
        }

        $rawId = (string) ($body['decisionId'] ?? '');
        if (!ctype_digit($rawId) || (int) $rawId <= 0) {
            return $this->forbidden();
        }
        $decisionId = new DecisionId((int) $rawId);

        $snapshot = $this->repository->findById($decisionId);
        if (null === $snapshot) {
            $this->flashMessenger->enqueue('flash.delete.not_found', ContextualFeedbackSeverity::INFO);

            return $this->redirectToList();
        }

        $backendUser = $this->backendUser();

        try {
            $result = $this->deleteHandler->handle(new DeleteDecisionCommand(
                snapshot: $snapshot,
                backendUserId: (int) ($backendUser['uid'] ?? 0),
                backendUserName: (string) ($backendUser['username'] ?? 'unknown'),
                permissions: $permissions,
            ));
        } catch (ForbiddenWriteException) {
            $this->flashMessenger->enqueue(
                'flash.delete.denied_origin',
                ContextualFeedbackSeverity::ERROR,
                [$snapshot->origin],
            );

            return $this->redirectToList();
        } catch (LapiAuthenticationException|LapiUnavailableException) {
            $this->flashMessenger->enqueue('flash.delete.failed', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToList();
        }

        if ($result->isSuccess()) {
            $this->flashMessenger->enqueue('flash.delete.success', ContextualFeedbackSeverity::OK, [
                $snapshot->id->toInt(),
            ]);
        } elseif ($result->isNotFound()) {
            $this->flashMessenger->enqueue('flash.delete.not_found', ContextualFeedbackSeverity::INFO);
        } else {
            $this->flashMessenger->enqueue('flash.delete.failed', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirectToList();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildDraft(array $body): DecisionDraft
    {
        $scopeRaw = (string) ($body['scope'] ?? '');
        $typeRaw = (string) ($body['type'] ?? '');
        $valueRaw = (string) ($body['value'] ?? '');
        $reasonRaw = (string) ($body['reason'] ?? '');

        $durationPreset = (string) ($body['durationPreset'] ?? '');
        $durationCustom = (string) ($body['durationCustom'] ?? '');
        $durationValue = 'custom' === $durationPreset ? $durationCustom : $durationPreset;

        return new DecisionDraft(
            scope: DecisionScope::from($scopeRaw),
            type: DecisionType::from($typeRaw),
            rawValue: $valueRaw,
            duration: new Duration($durationValue),
            reason: new Reason($reasonRaw),
        );
    }

    private function hasExistingDecisionsForValue(DecisionDraft $draft): bool
    {
        try {
            $existing = $this->repository->findActive(
                new \Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection(
                    scope: $draft->scope,
                ),
                new \Koh\CrowdsecBridge\Domain\ValueObject\Pagination(1),
            );
        } catch (LapiUnavailableException|LapiAuthenticationException) {
            return false;
        }

        foreach ($existing as $decision) {
            if ($decision->value === $draft->normalisedValue()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function renderNewForm(
        ServerRequestInterface $request,
        OperatorPermissions $permissions,
        array $body,
        ?InvalidDecisionDraftException $validationError,
        bool $duplicateWarning = false,
    ): ResponseInterface {
        $submitToken = $this->submitTokens->generate($GLOBALS['BE_USER'] ?? null);

        return $this->render($request, 'New', [
            'permissions' => $permissions,
            'formToken' => $this->formTokenValidator->generate($request, 'add'),
            'submitToken' => $submitToken,
            'formValues' => $body,
            'validationError' => $validationError,
            'duplicateWarning' => $duplicateWarning,
            'scopes' => DecisionScope::cases(),
            'types' => DecisionType::cases(),
            'durationPresets' => ['1h', '24h', '7d', '30d', 'custom'],
        ]);
    }

    private function deleteTokenFor(ServerRequestInterface $request, OperatorPermissions $permissions): string
    {
        if ($permissions->canDeleteOwn || $permissions->canDeleteForeign) {
            return $this->formTokenValidator->generate($request, 'delete');
        }

        return '';
    }

    private function safeList(ListDecisionsQuery $query): ?\Koh\CrowdsecBridge\Application\Dto\DecisionListView
    {
        try {
            return $this->listHandler->handle($query);
        } catch (LapiUnavailableException|LapiAuthenticationException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(ServerRequestInterface $request, string $template, array $variables): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle(
            $this->flashMessenger->translate('mlang_tabs_tab', 'locallang_module'),
            $this->flashMessenger->translate('module.title.list'),
        );
        $moduleTemplate->assignMultiple($variables);
        $moduleTemplate->assign('routeTokens', new RouteTokens($this->uriBuilder)->for(['list' => self::MODULE_IDENTIFIER, 'check' => self::MODULE_IDENTIFIER . '.check', 'alerts' => self::MODULE_IDENTIFIER . '.alerts']));

        return new HtmlResponse((string) $moduleTemplate->renderResponse($template)->getBody());
    }

    private function redirectToList(): ResponseInterface
    {
        $location = (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER);

        return new RedirectResponse($location, 303);
    }

    private function forbidden(): ResponseInterface
    {
        return new HtmlResponse('Forbidden', 403);
    }

    private function auditDeniedWrite(DecisionWriteAction $action, string $reasonCode): void
    {
        $backendUser = $this->backendUser();
        $this->writeAudit->logWrite(new DecisionWriteAuditContext(
            action: $action,
            backendUserId: (int) ($backendUser['uid'] ?? 0),
            backendUserName: (string) ($backendUser['username'] ?? 'anonymous'),
            status: 'denied',
            errorMessage: $reasonCode,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backendUser(): ?array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (null === $user) {
            return null;
        }

        return $user->user ?? null;
    }

    private function parseScope(mixed $raw): ?DecisionScope
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return DecisionScope::tryFrom($raw);
    }

    private function parseType(mixed $raw): ?DecisionType
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return DecisionType::tryFrom($raw);
    }

    private function parseOrigin(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return $raw;
    }

    private function parsePage(mixed $raw): int
    {
        if (\is_int($raw)) {
            return max(1, $raw);
        }
        if (\is_string($raw) && ctype_digit($raw)) {
            return max(1, (int) $raw);
        }

        return 1;
    }
}
