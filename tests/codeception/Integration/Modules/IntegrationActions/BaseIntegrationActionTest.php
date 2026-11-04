<?php

namespace Tests\Integration\Modules\IntegrationActions;

use FluentCart\App\Modules\IntegrationActions\BaseIntegrationAction;
use FluentCart\App\Modules\IntegrationActions\GlobalIntegrationActionHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

class BaseIntegrationActionTest extends WPTestCase
{
    private function makeAction(string $slug): BaseIntegrationAction
    {
        return new class ($slug) extends BaseIntegrationAction {
            private string $slugValue;
            public int $hooksRegistered = 0;
            public int $handled = 0;
            public function __construct(string $slug)
            {
                parent::__construct();
                $this->slugValue = $slug;
            }
            public function getSlug(): string
            {
                return $this->slugValue;
            }
            public function handle()
            {
                $this->handled++;
            }
            public function registerHooks()
            {
                $this->hooksRegistered++;
            }
        };
    }

    // Spec 168: GlobalIntegrationActionHandler::register wires fluentcart_loaded; init() wires init → do_action(register_integration_action)
    public function test_global_action_handler_register_binds_fluentcart_loaded(): void
    {
        $handler = new GlobalIntegrationActionHandler();
        $handler->register();
        $this->assertNotFalse(has_action('fluentcart_loaded'));
    }

    public function test_global_action_handler_init_binds_register_action_to_init(): void
    {
        // init()'s inner add_action('init', closure) registers a callback that emits register_integration_action.
        // Avoid re-firing 'init' globally (would re-register blocks). Invoke the bound closure directly.
        global $wp_filter;
        $before = isset($wp_filter['init']) ? count($wp_filter['init']->callbacks[10] ?? []) : 0;

        $handler = new GlobalIntegrationActionHandler();
        $handler->init();

        $this->assertArrayHasKey('init', $wp_filter);
        $after = count($wp_filter['init']->callbacks[10] ?? []);
        $this->assertGreaterThan($before, $after, 'init() must bind a new closure at priority 10');

        // Grab the last-added closure at priority 10 and invoke directly — fires register_integration_action without re-triggering all init hooks
        $callbacks = $wp_filter['init']->callbacks[10];
        $last = end($callbacks);

        $fired = 0;
        add_action('fluent_cart/register_integration_action', function () use (&$fired) { $fired++; });

        call_user_func($last['function']);
        $this->assertSame(1, $fired);
    }

    // Spec 169: BaseIntegrationAction::init wires get_global_integration_actions filter + calls registerHooks
    public function test_init_adds_filter_and_calls_register_hooks(): void
    {
        $slug = 'test_action_' . wp_generate_password(6, false);
        $action = $this->makeAction($slug);
        $action->init();

        $this->assertSame(1, $action->hooksRegistered, 'registerHooks must run once on init');

        $actions = apply_filters('fluent_cart/integration/get_global_integration_actions', []);
        $this->assertIsArray($actions);
        $slugs = array_column($actions, 'slug');
        $this->assertContains($slug, $slugs);
    }

    // Spec 170: register() pushes to shared static $actions list; persists across calls
    public function test_register_pushes_self_to_static_list(): void
    {
        $slug1 = 'slug_a_' . wp_generate_password(4, false);
        $slug2 = 'slug_b_' . wp_generate_password(4, false);

        $a = $this->makeAction($slug1);
        $b = $this->makeAction($slug2);

        $list1 = $a->register();
        $list2 = $b->register();

        $slugs = array_column($list2, 'slug');
        $this->assertContains($slug1, $slugs, 'first registered slug must survive into second list (static array)');
        $this->assertContains($slug2, $slugs);
        $this->assertGreaterThanOrEqual(count($list1), count($list2));
    }

    // Spec 171: GlobalIntegrationActionHandler::getAll returns filter result
    public function test_get_all_returns_filter_result(): void
    {
        $filter = function () {
            return [['slug' => 'fake_global', 'instance' => null]];
        };
        add_filter('fluent_cart/integration/get_global_integration_actions', $filter);
        $result = GlobalIntegrationActionHandler::getAll();
        remove_filter('fluent_cart/integration/get_global_integration_actions', $filter);

        $this->assertSame([['slug' => 'fake_global', 'instance' => null]], $result);
    }
}
