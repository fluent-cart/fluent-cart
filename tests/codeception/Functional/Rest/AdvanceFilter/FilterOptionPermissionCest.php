<?php

namespace Tests\Functional\Rest\AdvanceFilter;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\LabelFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/advanced-filter.md → "Loading options for a filter dropdown".
 *
 * GET /advance_filter/get-filter-options serves many unrelated lists behind one
 * URL, so it carries no `permissions` meta. A permission on the route would let
 * whoever holds the weakest one read every other list; instead the permission
 * belongs to the requested `remote_data_key`, resolved per key in
 * AdvanceFilterController::resolvePermission().
 *
 * A denial is an empty result rather than a 403, because the caller is a select
 * input inside a filter panel, where a rejected request surfaces as an error
 * toast on a control the user may never have touched.
 *
 * That design makes these tests easy to write wrongly: a denied key and a key
 * with no data BOTH answer `options: []`, so a test that only asserts `[]`
 * passes even if the permission gate is removed entirely. Every test below
 * therefore guarantees the allowed branch would return something — by
 * registering an options provider, or by creating a row — so that an empty
 * result can only have come from the gate.
 *
 * Items 1-6 of the spec are a role matrix and stay unchecked: expected allow or
 * deny per role must come from the human-authored permissions contract, which
 * is not written yet.
 */
class FilterOptionPermissionCest
{
    /**
     * Held as a closure rather than a method: any public method on a Cest is
     * collected as a test, and a private one cannot be invoked by WordPress
     * through an array callable.
     *
     * @var callable|null
     */
    private $probeProvider = null;

    public function _before(FunctionalTester $I): void
    {
        $this->probeProvider = static function () {
            return [['value' => 1, 'label' => 'Reached the provider']];
        };

        add_filter('fluent_cart/advanced_filter_options_a_key_no_one_declared', $this->probeProvider);
        add_filter('fluent_cart/advanced_filter_options_a_neighbouring_key', $this->probeProvider);
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('fluent_cart/advanced_filter_options_a_key_no_one_declared', $this->probeProvider);
        remove_filter('fluent_cart/advanced_filter_options_a_neighbouring_key', $this->probeProvider);

        $this->probeProvider = null;
    }

    /**
     * Spec: Cases/advanced-filter.md [7]
     *   "Logged-out visitor cannot load filter options."
     */
    public function loggedOutVisitorCannotLoadFilterOptions(FunctionalTester $I): void
    {
        $I->logout();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'labels',
        ]);

        Assert::assertSame(
            401,
            $status,
            'Per-key resolution is a second gate, not the only one — the policy stops a visitor first. '
            . 'Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/advanced-filter.md [8]
     *   "An unrecognised kind of option returns an empty list rather than an error."
     */
    public function anUnrecognisedKindOfOptionReturnsAnEmptyListRatherThanAnError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'a_key_no_one_declared',
        ]);

        Assert::assertSame(
            200,
            $status,
            'A 403 would surface as an error toast on a filter control the user may not have touched'
        );

        Assert::assertArrayNotHasKey('code', $body, 'A refusal must not carry an error code');
    }

    /**
     * Spec: Cases/advanced-filter.md [9]
     *   "An administrator can load options for a list they hold a permission for."
     */
    public function anAdministratorCanLoadOptionsForAListTheyHoldAPermissionFor(FunctionalTester $I): void
    {
        (new LabelFactory())->create();

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'labels',
        ]);

        Assert::assertSame(200, $status, 'A listed key must answer. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty(
            $body['options'] ?? [],
            'The allowed branch must actually return the row, or the denial tests below prove nothing'
        );
    }

    /**
     * Spec: Cases/advanced-filter.md [10]
     *   "A key with no declared permission is refused even to an administrator."
     */
    public function aKeyWithNoDeclaredPermissionIsRefusedEvenToAnAdministrator(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'a_key_no_one_declared',
        ]);

        Assert::assertSame(200, $status, 'A refusal here is still a well-formed answer');
        Assert::assertSame(
            [],
            $body['options'] ?? null,
            'A provider IS registered for this key, so the only thing that can empty the result is the '
            . 'permission gate defaulting to denied. Flip that default to allowed and this fails.'
        );
    }

    /**
     * Spec: Cases/advanced-filter.md [11]
     *   "A module can serve its own list without core knowing about it."
     */
    public function aModuleCanServeItsOwnListWithoutCoreKnowingAboutIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        add_filter('fluent_cart/advanced_filter_options_a_neighbouring_key', '__return_true');
        add_filter('fluent_cart/advanced_filter_options_permission_an_addon_key', '__return_true');
        add_filter('fluent_cart/advanced_filter_options_an_addon_key', $this->probeProvider);

        [, $ownKey] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'an_addon_key',
        ]);

        [, $neighbouringKey] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'a_neighbouring_key',
        ]);

        remove_filter('fluent_cart/advanced_filter_options_a_neighbouring_key', '__return_true');
        remove_filter('fluent_cart/advanced_filter_options_permission_an_addon_key', '__return_true');
        remove_filter('fluent_cart/advanced_filter_options_an_addon_key', $this->probeProvider);

        Assert::assertSame(
            'Reached the provider',
            $ownKey['options'][0]['label'] ?? null,
            'The hook is named after the key, which is how Pro answers a core route for a list core never declared'
        );

        Assert::assertSame(
            [],
            $neighbouringKey['options'] ?? null,
            'The neighbouring key has a provider too, so granting one key must not let the next one through'
        );
    }

    /**
     * Spec: Cases/advanced-filter.md [12]
     *   "A module can refuse a list core would otherwise allow."
     */
    public function aModuleCanRefuseAListCoreWouldOtherwiseAllow(FunctionalTester $I): void
    {
        (new LabelFactory())->create();

        $I->loginAsSuperAdmin();

        add_filter('fluent_cart/advanced_filter_options_permission_labels', '__return_false');

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'labels',
        ]);

        remove_filter('fluent_cart/advanced_filter_options_permission_labels', '__return_false');

        Assert::assertSame(200, $status, 'A refusal is still a well-formed answer');
        Assert::assertSame(
            [],
            $body['options'] ?? null,
            'A label exists and an administrator may read labels, so only the module refusing can empty this. '
            . 'Delete the apply_filters() in resolvePermission() and this fails.'
        );
    }
}
