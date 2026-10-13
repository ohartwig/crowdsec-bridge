<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Controller;

use Koh\CrowdsecBridge\Application\Command\AddDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Contract\FormTokenValidatorInterface;
use Koh\CrowdsecBridge\Application\Query\CheckIpQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListAlertsQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListDecisionsQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\OperatorPermissionsResolverInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use Koh\CrowdsecBridge\Presentation\Backend\Controller\DecisionController;
use Koh\CrowdsecBridge\Presentation\Backend\Support\FlashMessenger;
use Koh\CrowdsecBridge\Presentation\Backend\Support\SubmitTokenStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

/**
 * Die Schranken des Backend-Moduls.
 *
 * Dieser Controller schreibt in eine Firewall: er sperrt und entsperrt
 * IP-Adressen. Von allem, was er tut, sind zwei Dinge sicherheitsrelevant —
 * ob er ohne Berechtigung verweigert, und ob er eine Verweigerung
 * protokolliert. Alles andere ist Darstellung.
 *
 * Beides zusammen ist wichtiger als jedes einzeln. Ein Modul, das verweigert
 * ohne es festzuhalten, laesst einen Versuch spurlos; eines, das protokolliert
 * aber durchlaesst, protokolliert einen Einbruch. Deshalb prueft jeder Test
 * hier beide Haelften.
 *
 * Bewusst nicht geprueft wird das Rendern: `ModuleTemplateFactory` ist final
 * und laesst sich nicht doubeln. Auf den Verweigerungspfaden wird sie nie
 * aufgerufen, weshalb hier eine Instanz ohne Konstruktor genuegt — sie ist
 * Platzhalter, nicht Mitspieler.
 */
final class DecisionControllerGuardsTest extends TestCase
{
    /** @var list<DecisionWriteAuditContext> */
    private array $audited = [];

    private OperatorPermissions $permissions;

    private bool $tokenIsValid = false;

    protected function setUp(): void
    {
        $this->audited = [];
        $this->permissions = new OperatorPermissions(
            canRead: false,
            canWrite: false,
            canDeleteOwn: false,
            canDeleteForeign: false,
        );
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    private function controller(): DecisionController
    {
        $resolver = self::createStub(OperatorPermissionsResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(fn(): OperatorPermissions => $this->permissions);

        $audit = self::createStub(WriteAuditLoggerInterface::class);
        $audit->method('logWrite')->willReturnCallback(
            function (DecisionWriteAuditContext $context): void {
                $this->audited[] = $context;
            },
        );

        $uriBuilder = self::createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn('/typo3/module/system/crowdsec');

        $tokens = self::createStub(FormTokenValidatorInterface::class);
        $tokens->method('isValid')->willReturnCallback(fn(): bool => $this->tokenIsValid);

        return new DecisionController(
            self::bare(ModuleTemplateFactory::class),
            self::bare(ListDecisionsQueryHandler::class),
            self::bare(GetLapiStatusQueryHandler::class),
            self::bare(CheckIpQueryHandler::class),
            self::bare(AddDecisionCommandHandler::class),
            self::bare(DeleteDecisionCommandHandler::class),
            self::bare(ListAlertsQueryHandler::class),
            self::bare(GetAlertDetailQueryHandler::class),
            $tokens,
            $audit,
            $resolver,
            self::createStub(DecisionRepositoryInterface::class),
            self::bare(FlashMessenger::class),
            self::bare(SubmitTokenStore::class),
            $uriBuilder,
        );
    }

    /**
     * Eine Instanz ohne Konstruktor.
     *
     * Die Handler und die beiden Support-Klassen sind `final` und lassen sich
     * nicht doubeln. Auf den Verweigerungspfaden wird keine von ihnen
     * aufgerufen, deshalb genuegt ein Platzhalter. Faengt ein Pfad doch an, sie
     * zu benutzen, faellt das sofort als Fehler auf — nicht als stiller
     * Fehlschlag.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function bare(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function request(): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn([]);

        return $request;
    }

    /**
     * Ohne Leserecht zeigt keine Ansicht Daten.
     *
     * Die Liste, die IP-Pruefung und beide Alarm-Ansichten haengen an
     * derselben Schranke. Sie stehen hier einzeln, weil jede von ihnen sie
     * selbst abfragt — eine vergessene Abfrage faellt nur auf, wenn man jede
     * einzeln fragt.
     *
     * @param \Closure(DecisionController, ServerRequestInterface): ResponseInterface $call
     */
    #[Test]
    #[DataProvider('readActions')]
    public function noReadPermissionMeansNoView(\Closure $call): void
    {
        $response = $call($this->controller(), $this->request());

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{\Closure(DecisionController, ServerRequestInterface): ResponseInterface}>
     */
    public static function readActions(): iterable
    {
        yield 'Liste' => [static fn(DecisionController $c, ServerRequestInterface $r): ResponseInterface => $c->listAction($r)];
        yield 'IP-Pruefung' => [static fn(DecisionController $c, ServerRequestInterface $r): ResponseInterface => $c->checkIpAction($r)];
        yield 'Alarmliste' => [static fn(DecisionController $c, ServerRequestInterface $r): ResponseInterface => $c->alertListAction($r)];
        yield 'Alarmdetail' => [static fn(DecisionController $c, ServerRequestInterface $r): ResponseInterface => $c->alertDetailAction($r)];
    }

    /**
     * Ohne Schreibrecht wird nicht geschrieben — und der Versuch steht im
     * Protokoll.
     *
     * Der Protokolleintrag ist der Teil, den man leicht vergisst: die
     * Verweigerung allein schuetzt den Betrieb, aber sie hinterlaesst keine
     * Spur. Wer wissen will, ob jemand es versucht hat, braucht beides.
     */
    #[Test]
    public function creatingWithoutWritePermissionIsRefusedAndAudited(): void
    {
        $response = $this->controller()->createAction($this->request());

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->audited);
        self::assertSame(DecisionWriteAction::WriteDenied, $this->audited[0]->action);
        self::assertSame('denied', $this->audited[0]->status);
        self::assertSame('no_operator_role', $this->audited[0]->errorMessage);
    }

    #[Test]
    public function deletingWithoutAnyDeletePermissionIsRefusedAndAudited(): void
    {
        $response = $this->controller()->deleteAction($this->request());

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->audited);
        self::assertSame(DecisionWriteAction::WriteDenied, $this->audited[0]->action);
    }

    /**
     * Loeschen kennt zwei Wege hinein: das allgemeine Schreibrecht oder das
     * Recht, fremde Eintraege zu loeschen. Wer nur das zweite hat, kommt an
     * der Berechtigungsschranke vorbei.
     *
     * Diese Unterscheidung ist der Grund, warum die Schranke beim Loeschen
     * anders aussieht als beim Anlegen — und genau deshalb steht sie hier:
     * eine spaetere Vereinheitlichung der beiden Abfragen wuerde diesem
     * Operator lautlos das Recht nehmen.
     *
     * Geprueft wird an der Abwesenheit des Protokolleintrags, nicht am
     * Rueckgabewert: der Aufruf scheitert danach an der fehlenden
     * Entscheidungsnummer und antwortet ebenfalls mit 403. Ein Vergleich auf
     * den Status wuerde also nicht zwischen 'Schranke griff' und 'Schranke
     * griff nicht' unterscheiden.
     */
    #[Test]
    public function deletingForeignEntriesPassesThePermissionGate(): void
    {
        $this->permissions = new OperatorPermissions(
            canRead: true,
            canWrite: false,
            canDeleteOwn: false,
            canDeleteForeign: true,
        );
        $this->tokenIsValid = true;

        $this->controller()->deleteAction($this->request());

        self::assertSame([], $this->audited, 'keine Verweigerung protokolliert');
    }

    /**
     * Ohne angemeldeten Backend-Benutzer traegt der Protokolleintrag
     * `anonymous` und die Benutzernummer 0, statt an einem fehlenden Wert zu
     * scheitern.
     *
     * Ein Protokoll, das genau dann abstuerzt, wenn niemand angemeldet ist,
     * verliert die Eintraege, die am ehesten interessant sind.
     */
    #[Test]
    public function anAnonymousAttemptIsStillRecorded(): void
    {
        $this->controller()->createAction($this->request());

        self::assertCount(1, $this->audited);
        self::assertSame(0, $this->audited[0]->backendUserId);
        self::assertSame('anonymous', $this->audited[0]->backendUserName);
    }
}
