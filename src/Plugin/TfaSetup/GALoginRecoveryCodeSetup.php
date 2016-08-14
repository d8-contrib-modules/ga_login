<?php

namespace Drupal\ga_login\Plugin\TfaSetup;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa\Plugin\TfaValidation\TfaRecoveryCode;
use Drupal\user\UserDataInterface;

/**
 * Recovery codes setup class to setup recovery codes validation.
 *
 * @TfaSetup(
 *   id = "tfa_recovery_code_setup",
 *   label = @Translation("TFA Recovery Code Setup"),
 *   description = @Translation("TFA Recovery Code Setup Plugin"),
 *   setupMessages = {
 *    "saved" = @Translation("Saved recovery codes."),
 *    "skipped" = @Translation("Recovery codes not saved.")
 *   }
 * )
 */
class GALoginRecoveryCodeSetup extends TfaRecoveryCode implements TfaSetupInterface {
  /**
   * The generated recovery codes.
   *
   * @var array
   */
  protected $codes;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserDataInterface $user_data, EncryptionProfileManagerInterface $encryption_profile_manager, EncryptServiceInterface $encrypt_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $user_data, $encryption_profile_manager, $encrypt_service);
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupForm(array $form, FormStateInterface $form_state, $reset = 0) {

    if (!$reset && $codes = $this->getCodes()) {
      $this->codes = $codes;
    }
    else {
      $this->codes = $this->generateCodes();
    }

    $form['codes'] = [
      '#title' => t('Your recovery codes'),
      '#theme' => 'item_list',
      '#items' => $this->codes,
      '#attributes' => ['class' => ['recovery-codes']],
    ];

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => t('<p><em>Print, save, or write down these codes for use in case you are without your otp application and need to log in.</em></p>'),
    ];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSetupForm(array $form, FormStateInterface $form_state) {
    // Do nothing, Recovery code setup has no form inputs.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSetupForm(array $form, FormStateInterface $form_state) {
    $this->storeCodes($this->codes);
    return TRUE;
  }

  /**
   * Generate recovery codes.
   *
   * Note, these are un-encrypted codes. For any long-term storage be sure to
   * encrypt.
   *
   * @return array $codes
   *   List of recovery codes for current account.
   */
  protected function generateCodes() {
    $codes = $this->auth->ga->generateRecoveryCodes($this->codeLimit);
    array_walk($codes, function (&$v, $k) {
      $v = implode(" ", str_split($v, 3));
    });
    return $codes;
  }

  /**
   * {@inheritdoc}
   */
  public function getOverview($params) {
    $output = [
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => t('Fallback: Recovery Codes'),
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => t('Generate recovery codes to login when you can not do TFA.'),
      ],
    ];

    if ($params['enabled']) {
      $output['link'] = [
        '#theme' => 'links',
        '#links' => [
          'admin' => [
            'title' => t('Show Codes'),
            'url' => Url::fromRoute('tfa.validation.setup', [
              'user' => $params['account']->id(),
              'method' => $params['plugin_id'],
            ]),
          ],
        ],
      ];

      $output['reset'] = [
        '#theme' => 'links',
        '#links' => [
          'admin' => [
            'title' => t('Reset Codes'),
            'url' => Url::fromRoute('tfa.plugin.reset', [
              'user' => $params['account']->id(),
              'method' => $params['plugin_id'],
              'reset' => 1,
            ]),
          ],
        ],
      ];
    }
    else {
      $output['disabled'] = [
        '#type' => 'markup',
        '#markup' => '<b>You have not setup a TFA OTP method yet.</b>',
      ];
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function getHelpLinks() {
    return $this->pluginDefinition['helpLinks'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupMessages() {
    return ($this->pluginDefinition['setupMessages']) ?: '';
  }

}
