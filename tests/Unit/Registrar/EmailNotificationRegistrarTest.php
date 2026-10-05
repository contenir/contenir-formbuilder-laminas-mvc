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
use Laminas\Mime\Message as MimeMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;

#[Group('unit')]
final class EmailNotificationRegistrarTest extends TestCase
{
    public function testLineBreaksInTheSubjectAreCollapsedSoTheEmailIsStillSent(): void
    {
        $message = $this->send('New entry from {field:name}', 'Body', ['name' => "Ann\r\nBcc: evil@example.com"]);

        self::assertSame('New entry from Ann Bcc: evil@example.com', $message->getSubject());
    }

    public function testHtmlTemplateEscapesSubmittedValues(): void
    {
        $message = $this->send('S', '<p>From {field:name}</p>', ['name' => '<img src=x onerror=alert(1)>']);

        $mime = $message->getBody();
        self::assertInstanceOf(MimeMessage::class, $mime);
        self::assertSame('<p>From &lt;img src=x onerror=alert(1)&gt;</p>', $mime->getParts()[1]->getRawContent());
    }

    public function testHtmlTemplateIsSentAsMultipartAlternative(): void
    {
        $message = $this->send('S', '<p>Hi {field:name}</p>', ['name' => 'Ann']);

        $mime = $message->getBody();
        self::assertInstanceOf(MimeMessage::class, $mime);
        self::assertSame(
            ['text/plain', 'text/html'],
            array_map(static fn ($part): string => $part->type, $mime->getParts()),
        );
    }

    public function testPlainTextTemplateStaysPlainWhenAValueContainsMarkup(): void
    {
        $message = $this->send('S', 'From {field:name}', ['name' => '<a href="https://evil.example">Click</a>']);

        self::assertSame('From <a href="https://evil.example">Click</a>', $message->getBody());
    }

    public function testFieldsTableTagMakesATemplateHtml(): void
    {
        $message = $this->send('S', 'Entry: {ENTRY:fields}', []);

        self::assertInstanceOf(MimeMessage::class, $message->getBody());
    }

    /**
     * Send one notification and return the message the transport received.
     *
     * @param array<string, mixed> $values
     */
    private function send(string $subjectTemplate, string $bodyTemplate, array $values): Message
    {
        $transport = new InMemory();
        $form      = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            notifications: [new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                subject: $subjectTemplate,
                bodyTemplate: $bodyTemplate,
            )],
        );
        $subject           = new BuilderForm();
        $subject->registry = new ArrayObject(['form' => $form, 'values' => $values]);

        (new EmailNotificationRegistrar(new TokenReplacer(), $transport))->update($subject);

        $message = $transport->getLastMessage();
        self::assertInstanceOf(Message::class, $message);

        return $message;
    }
}
