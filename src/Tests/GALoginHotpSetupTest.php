<?php

namespace Drupal\ga_login\Tests;

use Base32\Base32;
use Drupal\simpletest\WebTestBase;
use Drupal\tfa\Tests\TFATestBase;
use Otp\GoogleAuthenticator;
use Otp\Otp;

/**
 * Tests the functionality of the Tfa plugins.
 *
 * @group GA_Login
 */
class GALoginHotpSetupTest extends TFATestBase {
  /**
   * The validation plugin manager to fetch plugin information.
   *
   * @var \Drupal\tfa\TfaSetupPluginManager
   */
  protected $tfaSetupManager;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    // Enable TFA module and the test module.
    parent::setUp();

    $this->tfaSetupManager = \Drupal::service('plugin.manager.tfa.setup');
  }

  /**
   * Test setup of TOTP.
   */
  public function testHotpSetup() {
    $account = $this->drupalCreateUser(['setup own tfa', 'disable own tfa']);
    $this->drupalLogin($account);

    $plugin = 'tfa_hotp';
    $this->config('tfa.settings')
         ->set('enabled', 1)
         ->set('validation_plugin', $plugin)
         ->set('encryption', 'test_encryption_profile')
      ->save();
    $setup_plugin = $this->tfaSetupManager->createInstance($plugin . '_setup', ['uid' => $account->id()]);
    $this->drupalGet('user/' . $account->id() . '/security/tfa');
    $this->assertText($this->uiStrings('setup-app'));

    $this->drupalGet('user/' . $account->id() . '/security/tfa/' . $plugin);
    $this->assertText($this->uiStrings('password-request'));

    $edit = [
      'current_pass' => $account->pass_raw,
    ];

    $this->drupalPostForm(NULL, $edit, t('Confirm'));

    // Fetch seed.
    $result = $this->xpath('//input[@name="seed"]');
    if (empty($result)) {
      $this->fail('Unable to extract seed from page. Aborting test.');
      return;
    }

    $seed = (string) $result[0]['value'];
    $setup_plugin->setSeed($seed);

    // Try invalid code.
    $edit = [
      'code' => substr(str_shuffle('1234567890'), 0, 6),
    ];
    $this->drupalPostForm(NULL, $edit, t('Verify and save'));
    // Failed code error.
    $this->assertText($this->uiStrings('invalid-code-retry'));

    // Submit valid code.
    $edit = [
      'code' => $this->auth->otp->hotp(Base32::decode($seed), 1),
    ];
    $this->drupalPostForm(NULL, $edit, t('Verify and save'));

    // Disable TFA.
    $this->assertText($this->uiStrings('tfa-disable'));
  }

  /**
   * Test setup of HOTP with fallbacks.
   */
  public function testTotpSetupWithFallback() {
    $account = $this->drupalCreateUser(['setup own tfa', 'disable own tfa']);
    $this->drupalLogin($account);

    $plugin = 'tfa_hotp';
    $fallback_plugin = 'tfa_recovery_code';
    $fallback_plugin_config = [
      $plugin => [$fallback_plugin => ['enable' => 1, 'settings'=> ['recovery_codes_amount' => 1], 'weight' => -2]],
    ];

    $this->config('tfa.settings')
      ->set('enabled', 1)
      ->set('validation_plugin', $plugin)
      ->set('fallback_plugins', $fallback_plugin_config)
      ->set('encryption', 'test_encryption_profile')
      ->save();

    // OTP Plugin Setup.
    $setup_plugin = $this->tfaSetupManager->createInstance($plugin . '_setup', ['uid' => $account->id()]);
    $this->drupalGet('user/' . $account->id() . '/security/tfa');
    // Setup otp link.
    $this->assertText($this->uiStrings('setup-app'));

    $this->drupalGet('user/' . $account->id() . '/security/tfa/' . $plugin);
    $this->assertText($this->uiStrings('password-request'));

    $edit = [
      'current_pass' => $account->pass_raw,
    ];

    $this->drupalPostForm(NULL, $edit, t('Confirm'));

    // Fetch seed.
    $result = $this->xpath('//input[@name="seed"]');
    if (empty($result)) {
      $this->fail('Unable to extract seed from page. Aborting test.');
      return;
    }

    $seed = (string) $result[0]['value'];
    $setup_plugin->setSeed($seed);

    // Try invalid code.
    $edit = [
      'code' => substr(str_shuffle('1234567890'), 0, 6),
    ];
    $this->drupalPostForm(NULL, $edit, t('Verify and save'));
    // Failed code error.
    $this->assertText($this->uiStrings('invalid-code-retry'));

    // Submit valid code.
    $edit = [
      'code' => $this->auth->otp->hotp(Base32::decode($seed), 1),
    ];

    // Post HOTP form.
    $this->drupalPostForm(NULL, $edit, t('Verify and save'));

    // Post recovery codes form.
    $this->drupalPostForm(NULL, NULL, t('Save'));

    // Disable TFA link.
    $this->assertText($this->uiStrings('tfa-disable'));
    // Fallback method now accessible.
    $this->assertText($this->uiStrings('otp-enabled-fallback'));

    $this->drupalGet('user/' . $account->id() . '/security/tfa/' . $fallback_plugin);
    $this->assertText($this->uiStrings('password-request'));

    $edit = [
      'current_pass' => $account->pass_raw,
    ];

    $this->drupalPostForm(NULL, $edit, t('Confirm'));
    $this->assertText($this->uiStrings('set-recovery-codes'));

    // @todo check whether recovery codes were saved.
  }

  /**
   * TFA module user interface strings.
   *
   * @param string $id
   *   ID of string.
   *
   * @return string
   *   UI message for corresponding id.
   */
  protected function uiStrings($id) {
    switch ($id) {
      case 'setup-app':
        return 'Set up application';

      case 'password-request':
        return 'Enter your current password';

      case 'pass-error':
        return 'Incorrect password';

      case 'app-step1':
        return 'Install authentication code application on your mobile or desktop device';

      case 'invalid-code-retry':
        return 'Invalid application code. Please try again.';

      case 'invalid-recovery-code':
        return 'Invalid recovery code.';

      case 'set-trust-skip':
        return 'Mark this browser as trusted or skip to continue and finish TFA setup';

      case 'set-recovery-codes':
        return 'Your recovery codes';

      case 'setup-complete':
        return 'TFA setup complete';

      case 'setup-trust':
        return 'Set trusted browsers';

      case 'setup-recovery':
        return 'Get recovery codes';

      case 'code-list':
        return 'View unused recovery codes';

      case 'app-desc':
        return 'Verification code is application generated and 6 digits long.';

      case 'tfa-disable':
        return 'Disable TFA';

      case 'tfa-disable-confirm':
        return 'Are you sure you want to disable TFA on account';

      case 'tfa-disabled':
        return 'TFA has been disabled';

      case 'otp-disabled-fallback':
        return 'You have not setup a TFA OTP method yet';

      case 'otp-enabled-fallback':
        return 'Show Codes';
    }
  }

}
