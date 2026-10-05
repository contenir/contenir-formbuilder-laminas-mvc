<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\InMemory;
use Laminas\Mail\Transport\TransportInterface;
use Laminas\Mime\Message as MimeMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplObserver;
use SplSubject;

use function array_keys;
use function array_map;
use function iterator_to_array;

#[Group('unit')]
final class EmailNotificationRegistrarTest extends TestCase
{
    /**
     * @return array<string, array{SplSubject}>
     */
    public static function silentSubjectProvider(): array
    {
        $form = self::form([new NotificationDefinition(
            id: 1,
            name: 'Admin',
            toAddress: 'a@example.com',
            subject: 'x',
        )]);

        return [
            'not a builder form' => [new class implements SplSubject {
                public function attach(SplObserver $observer): void {}

                public function detach(SplObserver $observer): void {}

                public function notify(): void {}
            }],
            'no registry'        => [new BuilderForm()],
            'no form definition' => [self::subject(['values' => []])],
            'spam submission'    => [self::subject(['form' => $form, 'spam' => true])],
            'only disabled'      => [self::subject(['form' => self::form([
                new NotificationDefinition(
                    id: 1,
                    name: 'Off',
                    toAddress: 'a@example.com',
                    enabled: false,
                ),
            ])])],
        ];
    }

    /**
     * @param list<NotificationDefinition> $notifications
     */
    private static function form(array $notifications): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            notifications: $notifications,
        );
    }

    /**
     * @param array<string, mixed> $registry
     */
    private static function subject(array $registry): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject($registry);

        return $form;
    }

    #[Test]
    public function failuresWithoutALoggerAreSilent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('send')->willThrowException(new RuntimeException('down'));

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update(self::subject([
            'form' => self::form([new NotificationDefinition(
                id: 1,
                name: 'A',
                toAddress: 'a@example.com',
                fromAddress: 'bad address',
                subject: 'S',
            )]),
        ]));
    }

    #[Test]
    public function fieldsTableTagMakesATemplateHtml(): void
    {
        $message = $this->send('Entry: {ENTRY:fields}', []);

        static::assertInstanceOf(MimeMessage::class, $message->getBody());
    }

    #[Test]
    public function htmlBodyIsSentAsMultipartAlternative(): void
    {
        $transport = new InMemory();
        $body      = '<html><head><title>T</title><style>p{}</style></head><body><script>x()</script>'
        . '<h1>Hi &amp; welcome</h1><p>Line one<br>Line two</p><div>A    lot</div><p></p><p></p><p></p></body></html>';

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update(self::subject([
            'form' => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                subject: 'S',
                bodyTemplate: $body,
            )]),
        ]));

        $message = $transport->getLastMessage();
        static::assertInstanceOf(Message::class, $message);
        $mime = $message->getBody();
        static::assertInstanceOf(MimeMessage::class, $mime);
        static::assertSame(
            ['text/plain', 'text/html'],
            array_map(static fn($part): string => $part->type, $mime->getParts()),
        );
        static::assertSame("Hi & welcome\nLine one\nLine two\nA lot", $mime->getParts()[0]->getRawContent());
        static::assertSame($body, $mime->getParts()[1]->getRawContent());
        static::assertStringContainsString(
            'multipart/alternative',
            $message->getHeaders()->get('Content-Type')->getFieldValue(),
        );
    }

    #[Test]
    public function htmlTemplateEscapesSubmittedValues(): void
    {
        $message = $this->send('<p>From {field:name}</p>', ['name' => '<img src=x onerror=alert(1)>']);

        $mime = $message->getBody();
        static::assertInstanceOf(MimeMessage::class, $mime);
        static::assertSame('<p>From &lt;img src=x onerror=alert(1)&gt;</p>', $mime->getParts()[1]->getRawContent());
    }

    #[Test]
    public function invalidOrEmptySenderAddressesAreSkipped(): void
    {
        $transport = new InMemory();
        $logger    = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with($this->stringStartsWith('Form notification address "{field:missing}" skipped: '));

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport, $logger))->update(self::subject([
            'form'   => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                fromAddress: '{field:missing}',
                replyTo: '{field:blank}',
                subject: 'S',
            )]),
            'values' => ['blank' => '  '],
        ]));

        $message = $transport->getLastMessage();
        static::assertInstanceOf(Message::class, $message);
        static::assertSame([0, 0], [$message->getFrom()->count(), $message->getReplyTo()->count()]);
    }

    #[Test]
    public function plainTextTemplateStaysPlainWhenAValueContainsMarkup(): void
    {
        $message = $this->send('From {field:name}', ['name' => '<a href="https://evil.example">Click</a>']);

        static::assertSame('From <a href="https://evil.example">Click</a>', $message->getBody());
    }

    #[Test]
    public function sendsAPlainTextNotificationWithResolvedTokens(): void
    {
        $transport = new InMemory();
        $subject   = self::subject([
            'form'   => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'admin@example.com, {field:email}; not-an-address',
                fromAddress: 'noreply@example.com',
                replyTo: '{field:email}',
                subject: 'New entry from {field:name}',
                bodyTemplate: 'Entry {entry:id} from {field:name}',
            )]),
            'values' => ['name' => "Ann\r\nBcc: evil@example.com", 'email' => 'ann@example.com'],
            'entry'  => ['id' => 42],
        ]);

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update($subject);

        $message = $transport->getLastMessage();
        static::assertInstanceOf(Message::class, $message);
        static::assertSame('New entry from Ann Bcc: evil@example.com', $message->getSubject());
        static::assertSame("Entry 42 from Ann\r\nBcc: evil@example.com", $message->getBody());
        static::assertSame(['admin@example.com', 'ann@example.com'], array_keys(iterator_to_array($message->getTo())));
        static::assertSame(['noreply@example.com'], array_keys(iterator_to_array($message->getFrom())));
        static::assertSame(['ann@example.com'], array_keys(iterator_to_array($message->getReplyTo())));
        static::assertSame([], array_keys(iterator_to_array($message->getBcc())));
    }

    #[Test]
    #[DataProvider('silentSubjectProvider')]
    public function sendsNothingForSpamOrMissingNotifications(SplSubject $subject): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->never())->method('send');

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update($subject);
    }

    #[Test]
    public function transportFailuresAreLoggedAndLaterNotificationsStillSend(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('SMTP down')), null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Form notification "First" failed for form "contact": SMTP down');

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport, $logger))->update(self::subject([
            'form'   => self::form([
                new NotificationDefinition(
                    id: 1,
                    name: 'First',
                    toAddress: 'a@example.com',
                    subject: 'S',
                ),
                new NotificationDefinition(
                    id: 2,
                    name: 'Second',
                    toAddress: 'b@example.com',
                    subject: 'S',
                ),
            ]),
            'values' => 'x',
            'entry'  => 'y',
        ]));
    }

    /**
     * Send one notification with $bodyTemplate and return the message.
     *
     * @param array<string, mixed> $values
     */
    private function send(string $bodyTemplate, array $values): Message
    {
        $transport = new InMemory();

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update(self::subject([
            'form'   => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                subject: 'S',
                bodyTemplate: $bodyTemplate,
            )]),
            'values' => $values,
        ]));

        $message = $transport->getLastMessage();
        static::assertInstanceOf(Message::class, $message);

        return $message;
    }
}
