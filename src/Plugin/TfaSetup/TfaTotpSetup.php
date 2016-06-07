<?php

namespace Drupal\ga_login\Plugin\TfaSetup;

use Base32\Base32;
use Drupal\Core\Link;
use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa\Plugin\TfaValidation\TfaTotp;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\Entity\User;

/**
 * @TfaSetup(
 *   id = "tfa_totp_setup",
 *   label = @Translation("TFA Totp Setup"),
 *   description = @Translation("TFA Totp Setup Plugin"),
 *   help_links = {
 *    "Google Authenticator (Android/iPhone/BlackBerry)" = "https://support.google.com/accounts/answer/1066447?hl=en",
 *    "Authy (Android/iPhone)" = "https://www.authy.com/thefuture#install-now",
 *    "FreeOTP (Android)" = "https://play.google.com/store/apps/details?id=org.fedorahosted.freeotp",
 *    "GAuth Authenticator (desktop)" = "https://github.com/gbraad/html5-google-authenticator"
 *   }
 * )
 */
class TfaTotpSetup extends TfaTotp implements TfaSetupInterface {
  /**
   * @var string Un-encrypted seed.
   */
  protected $seed;

  /**
   * @var string
   */
  protected $namePrefix;

  /**
   * @copydoc TfaBasePlugin::__construct()
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserDataInterface $user_data) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $user_data);
    // Generate seed.
    $this->seed = $this->createSeed();
    $this->namePrefix = \Drupal::config('tfa.settings')->get('name_prefix');
  }

  /**
   * @copydoc TfaSetupPluginInterface::getSetupForm()
   */
  public function getSetupForm(array $form, FormStateInterface $form_state) {
    $help_links = $this->getHelpLinks();

    foreach($help_links as $item => $link)
      $items[] = Link::fromTextAndUrl($item, Url::fromUri($link, ['attributes' => ['target'=>'_blank']]));

    $markup = ['#theme' => 'item_list', '#items' => $items, '#title' => t('Install authentication code application on your mobile or desktop device:')];
    $form['apps'] = array(
      '#type' => 'markup',
      '#markup' => \Drupal::service('renderer')->render($markup),
    );
    $form['info'] = array(
      '#type' => 'markup',
      '#markup' => t('<p>The two-factor authentication application will be used during this setup and for generating codes during regular authentication. If the application supports it, scan the QR code below to get the setup code otherwise you can manually enter the text code.</p>'),
    );
    $form['seed'] = array(
      '#type' => 'textfield',
      '#value' => $this->seed,
      '#disabled' => TRUE,
      '#description' => t('Enter this code into your two-factor authentication app or scan the QR code below.'),
    );
    // QR image of seed.
    if (file_exists(drupal_get_path('module', 'tfa_basic') . '/includes/qrcodejs/qrcode.min.js')) {
      $form['qr_image_wrapper']['qr_image'] = array(
        '#markup' => '<div id="tfa-qrcode"></div>',
      );
      $qrdata = 'otpauth://totp/' . $this->accountName() . '?secret=' . $this->seed;
      $form['qr_image_wrapper']['qr_image']['#attached']['library'][] = array('tfa_basic', 'qrcodejs');
      $form['qr_image_wrapper']['qr_image']['#attached']['js'][] = array(
        'data' => 'jQuery(document).ready(function () { new QRCode(document.getElementById("tfa-qrcode"), "' . $qrdata . '");});',
        'type' => 'inline',
        'scope' => 'footer',
        'weight' => 5,
      );
    }
    else {
      $form['qr_image'] = array(
        '#markup' => '<img src="' . $this->getQrCodeUrl($this->seed) .'" alt="QR code for TFA setup">',
      );
    }
    // Include code entry form.
    $form = $this->getForm($form, $form_state);
    $form['actions']['login']['#value'] = t('Verify and save');
    // Alter code description.
    $form['code']['#description'] = t('A verification code will be generated after you scan the above QR code or manually enter the setup code. The verification code is six digits long.');
    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, FormStateInterface $form_state) {
    if (!$this->validate($form_state->getValue('code'))) {
      $this->errorMessages['code'] = t('Invalid application code. Please try again.');
//      $form_state->setErrorByName('code', $this->errorMessages['code']);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @copydoc TfaBasePlugin::validate()
   */
  protected function validate($code) {
    return $this->auth->otp->checkTotp(Base32::decode($this->seed), $code, $this->timeSkew);
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, FormStateInterface $form_state) {
    // Write seed for user.
    $this->storeSeed($this->seed);
    return TRUE;
  }

  /**
   * Get a URL to a Google Chart QR image for a seed.
   *
   * @param string $seed
   * @return string URL
   */
  protected function getQrCodeUrl($seed) {
    // Note, this URL is over https but does leak the seed and account
    // email address to Google. See README.txt for local QR code generation
    // using qrcode.js.
    return $this->auth->ga->getQrCodeUrl('totp', $this->accountName(), $seed);
  }

  /**
   * Create OTP seed for account.
   *
   * @return string Seed.
   */
  protected function createSeed() {
    return $this->auth->ga->generateRandom();
  }

  /**
   * Save seed for account.
   *
   * @param string $seed Seed.
   */
  protected function storeSeed($seed) {
    // Encrypt seed for storage.
    $encrypted = $this->encrypt($seed);

    $record = ['tfa_totp_seed' => [
                'seed' => Base32::encode($encrypted),
                'created' => REQUEST_TIME]
              ];

    $this->setUserData('tfa', $record);
  }

  /**
   * Get account name for QR image.
   *
   * @return string URL encoded string.
   */
  protected function accountName() {
    /** @var User $account */
    $account =  User::load($this->configuration['uid']);
    return urlencode($this->namePrefix . '-' . $account->getUsername());
  }

  /**
   * Get list of helper links for the plugin
   *
   * @return array List of helper links
   */
  public function getHelpLinks(){
    return $this->pluginDefinition['help_links'];
  }

}
