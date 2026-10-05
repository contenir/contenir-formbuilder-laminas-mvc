<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit;

use Contenir\FormBuilder\Laminas\Mvc\ConfigProvider;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Factory\SubmitControllerFactory;
use Contenir\FormBuilder\Laminas\Mvc\Module;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function moduleConfigIsTheConfigProviderOutput(): void
    {
        static::assertSame((new ConfigProvider())(), (new Module())->getConfig());
    }

    #[Test]
    public function providesServicesControllersHelpersRoutesAndDefaults(): void
    {
        $config = (new ConfigProvider())();

        static::assertSame(
            ['service_manager', 'controllers', 'view_helpers', 'router', 'formbuilder'],
            array_keys($config),
        );
        static::assertSame(
            [SubmitController::class => SubmitControllerFactory::class],
            $config['controllers']['factories'],
        );
        static::assertSame('/forms/submit/:slug', $config['router']['routes']['forms-submit']['options']['route']);
        static::assertSame(
            ['db_adapter' => Adapter::class, 'site_context' => [], 'token_resolvers' => [], 'observers' => []],
            $config['formbuilder'],
        );
        static::assertSame(['formMarkup'], array_keys($config['view_helpers']['invokables']));
        static::assertSame(['formStashedState'], array_keys($config['view_helpers']['factories']));
        static::assertCount(7, $config['service_manager']['factories']);
    }
}
