<?php

namespace Tests\Functional\Rest\Settings;

use Codeception\Example;
use FluentCart\Api\StoreSettings;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

class ComplianceSettingsCest
{
    public function adminSeesAutomaticLoginOnAndEmailVerificationOnByDefault(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $settings = get_option('fluent_cart_store_settings', []);
        unset($settings['auto_login_after_account_creation'], $settings['require_customer_email_verification']);
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();

        [$status, $body] = $I->get('/fluent-cart/v2/settings/store', ['settings_name' => 'compliance']);

        Assert::assertSame(200, $status, 'The Compliance settings must be available to the store administrator.');
        Assert::assertSame('yes', $body['settings']['auto_login_after_account_creation'], 'Stores without a saved choice must default to automatic login.');
        Assert::assertSame('yes', $body['settings']['require_customer_email_verification'], 'Stores without a saved choice must require email verification.');
        Assert::assertTrue(\FluentCart\App\Services\CustomerIdentity\EmailVerificationService::isEnabled(), 'The verification gate must follow the default.');
        Assert::assertSame('Compliance', $body['fields']['compliance']['title'], 'The new settings page must expose its form schema.');
        $schema = $body['fields']['compliance']['schema'];
        $fields = [
            'auto_login_after_account_creation'   => [$schema['account_login_grid']['schema']['fields']['schema']['auto_login_after_account_creation'], 'yes'],
            'require_customer_email_verification' => [$schema['email_verification_grid']['schema']['fields']['schema']['require_customer_email_verification'], 'yes'],
        ];
        foreach ($fields as $key => [$field, $default]) {
            $values = array_column($field['options'], 'value');
            sort($values);
            Assert::assertSame(['no', 'yes'], $values, 'The form must offer both supported settings for ' . $key . '.');
            Assert::assertSame($default, $field['value'], 'The form must preselect the stored default for ' . $key . '.');
        }
    }

    /**
     * @example {"submitted": "yes", "saved": "yes"}
     * @example {"submitted": "no", "saved": "no"}
     * @example {"submitted": "invalid", "saved": "no"}
     * @example {"submitted": ["yes"], "saved": "no"}
     */
    public function adminCanSaveAndReloadAComplianceChoice(FunctionalTester $I, Example $example): void
    {
        $I->loginAsSuperAdmin();
        (new StoreSettings())->set('auto_login_after_account_creation', $example['saved'] === 'yes' ? 'no' : 'yes');
        $creationMode = (new StoreSettings())->get('user_account_creation_mode');

        [$status, $body] = $I->post('/fluent-cart/v2/settings/store', [
            'settings_name' => 'compliance',
            'auto_login_after_account_creation' => $example['submitted'],
        ]);

        Assert::assertSame(200, $status, 'Saving Compliance settings must succeed: ' . wp_json_encode($body));
        $persisted = get_option('fluent_cart_store_settings');
        Assert::assertSame($example['saved'], $persisted['auto_login_after_account_creation'], 'Only an explicit yes may enable automatic login in storage.');
        Assert::assertSame($creationMode, $persisted['user_account_creation_mode'], 'Saving Compliance must preserve the account creation mode.');

        StoreSettings::clearCache();
        [$status, $body] = $I->get('/fluent-cart/v2/settings/store', ['settings_name' => 'compliance']);
        Assert::assertSame(200, $status, 'Settings must be readable after saving.');
        Assert::assertSame($example['saved'], $body['settings']['auto_login_after_account_creation'], 'The next settings load must return the persisted choice.');
    }
}
