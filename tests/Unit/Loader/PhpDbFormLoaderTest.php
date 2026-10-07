<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Loader;

use Contenir\FormBuilder\Laminas\Mvc\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Db\ArrayResult;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function preg_match;

/**
 * Feeds the loader rows that leave out optional columns, which the bundled
 * schema always fills from its column defaults.
 */
#[Group('unit')]
final class PhpDbFormLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    private static function table(string $sql): string
    {
        return preg_match('/ FROM (\w+)/', $sql, $matches) === 1 ? $matches[1] : $sql;
    }

    #[Test]
    public function doesNotQueryTheLevelsBelowAnEmptyOne(): void
    {
        $this->loader(['form_section' => []])->loadById(1);

        static::assertSame(
            ['form', 'form_section', 'form_notification', 'form_webhook'],
            array_map(self::table(...), $this->queries),
        );
    }

    #[Test]
    public function fieldColumnsMissingFromTheRowTakeTheirDefaults(): void
    {
        $field = $this->loader([
            'form_field' => [['form_field_id' => 5, 'form_row_id' => 4, 'type' => 'text', 'name' => 'n']],
        ])
            ->loadById(1)
            ?->getAllFields()[0];

        static::assertSame([true, false, 4], [$field?->showLabel, $field?->required, $field?->colSpan]);
    }

    #[Test]
    public function notificationsAndWebhooksMissingTheEnabledColumnAreEnabled(): void
    {
        $form = $this->loader([
            'form_notification' => [['form_notification_id' => 1, 'name' => 'n']],
            'form_webhook'      => [['form_webhook_id' => 1, 'name' => 'w']],
        ])->loadById(1);

        static::assertSame([true, true], [$form?->notifications[0]->enabled, $form?->webhooks[0]->enabled]);
    }

    /**
     * A loader over one form with one section, group and row, plus $rows by
     * table; every query is recorded in {@see $queries}.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     */
    private function loader(array $rows): PhpDbFormLoader
    {
        $rows += [
            'form'         => [['form_id' => 1, 'slug' => 'contact', 'title' => 'Contact']],
            'form_section' => [['form_section_id' => 2, 'form_id' => 1, 'key' => 'main']],
            'form_group'   => [['form_group_id' => 3, 'form_section_id' => 2]],
            'form_row'     => [['form_row_id' => 4, 'form_group_id' => 3]],
        ];

        $statements = [];
        $adapter    = $this->createStub(AdapterInterface::class);
        $adapter->method('prepareQuery')
            ->willReturnCallback(function (string $sql) use (&$statements): StatementInterface {
                $this->queries[] = $sql;
                $statement       = $this->createStub(StatementInterface::class);
                $statements[]    = [$statement, $sql];

                return $statement;
            });
        $adapter->method('executeQuery')
            ->willReturnCallback(static function (StatementInterface $statement) use (
                &$statements,
                $rows,
            ): ArrayResult {
                foreach ($statements as [$prepared, $sql]) {
                    if ($prepared === $statement) {
                        return new ArrayResult($rows[self::table($sql)] ?? []);
                    }
                }

                return new ArrayResult([]);
            });

        return new PhpDbFormLoader($adapter);
    }
}
