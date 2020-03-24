<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use GuzzleHttp\Client;
use Ogone\Ecommerce\EcommercePaymentResponse;
use Ogone\HashAlgorithm;
use Ogone\Passphrase;
use Ogone\PaymentResponse;
use Ogone\ShaComposer\AllParametersShaComposer;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "ingenico_ecommerce",
 *   label = "Ingenico e-Commerce (off-site)",
 *   display_label = "Ingenico e-Commerce",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_ingenico\PluginForm\ECommerceOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class ECommerce extends OffsitePaymentGatewayBase implements EcommerceInterface {

  // Both payment method configuration form as well as payment operations
  // (capture/void/refund) are common to Ingenico DirectLink and e-Commerce.
  use ConfigurationTrait {
    defaultConfiguration as protected defaultConfigurationTrait;
    buildConfigurationForm as protected buildConfigurationFormTrait;
    validateConfigurationForm as protected validateConfigurationFormTrait;
    submitConfigurationForm as protected submitConfigurationFormTrait;
  }
  use OperationsTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager,  TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    // We need to define httpClient here for capture/void/refund operations,
    // as it is not passed to off-site plugins constructor.
    $this->httpClient = new Client();
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);
    $feedback = $this->getIngenicoFeedbackFromRequest($request);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce payment response: <pre>@body</pre>', [
          '@body' => var_export($feedback->all(), TRUE),
        ]);
    }

    // Common response processing for both redirect back and async notification.
    $payment = $this->processFeedback($feedback);

    // Do not update payment state here - it should be done from the received
    // notification only, and considering that usually notification is received
    // even before the user returns from the off-site redirect, at this point
    // the state tends to be already the correct one.
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);
    $feedback = $this->getIngenicoFeedbackFromRequest($request);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce notification: <pre>@body</pre>', [
          '@body' => var_export($feedback->all(), TRUE),
        ]);
    }

    // Common response processing for both redirect back and async notification.
    $payment = $this->processFeedback($feedback);

    // Let's also update payment state here - it's safer doing it from received
    // asynchronous notification rather than from the redirect back from the
    // off-site redirect.
    $state = $feedback->get('STATUS') == PaymentResponse::STATUS_AUTHORISED ? 'authorization' : 'completed';
    $payment->set('state', $state);
    $payment->setAuthorizedTime(\Drupal::time()->getRequestTime());
    if ($feedback->get('STATUS') != PaymentResponse::STATUS_AUTHORISED) {
      $payment->setCompletedTime(\Drupal::time()->getRequestTime());
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'language_from_ui' => 0,
      'language_from_ui_map' => [],
      'enable_ingenico_brands' => 0,
      'ingenico_brands' => [],
    ] + $this->defaultConfigurationTrait();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = $this->buildConfigurationFormTrait($form, $form_state);

    $form['language_from_ui'] = [
      '#type' => 'details',
      '#title' => $this->t('Language from UI'),
      '#description' => $this->t('This is a function to show Ingenico UI in the same language as website.'),
      '#open' => $this->configuration['language_from_ui'],
    ];

    $form['language_from_ui']['language_from_ui'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Ingenico UI in the same language as website (if possible)'),
      '#default_value' => $this->configuration['language_from_ui'],
    ];

    $form['language_from_ui']['language_from_ui_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ingenico language map'),
      '#description' => $this->t('This map defines how the website language should be converted into Ingenico UI language. Each line has the format: website_language_id|ingenico_language_id.'),
      '#default_value' => $this->convertLanguageFromUiMapFromConfiguration($this->configuration['language_from_ui_map']),
    ];

    $form['ingenico_brands'] = [
      '#type' => 'details',
      '#title' => $this->t('Ingenico payment methods'),
      '#description' => $this->t('This is a function to enable user to choose Ingenico payment method before offsite redirection.'),
      '#open' => $this->configuration['enable_ingenico_brands'],
    ];

    $form['ingenico_brands']['enable_ingenico_brands'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable user to choose Ingenico payment method on Drupal side'),
      '#default_value' => $this->configuration['enable_ingenico_brands'],
    ];

    $form['ingenico_brands']['ingenico_brands'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Payment methods'),
      '#description' => $this->t('Specify one payment method per line. Format title|PM|BRAND.'),
      '#default_value' => $this->convertIngenicoBrandsFromConfiguration($this->configuration['ingenico_brands']),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->validateConfigurationFormTrait($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->submitConfigurationFormTrait($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['language_from_ui'] = $values['language_from_ui']['language_from_ui'];
      $this->configuration['language_from_ui_map'] = $this->convertLanguageFromUiMapFromUserInput($values['language_from_ui']['language_from_ui_map']);

      $this->configuration['enable_ingenico_brands'] = $values['ingenico_brands']['enable_ingenico_brands'];
      $this->configuration['ingenico_brands'] = $this->convertIngenicoBrandsFromUserInput($values['ingenico_brands']['ingenico_brands']);
    }
  }

  /**
   * Common response processing for both redirect back and async notification.
   *
   * @param \Symfony\Component\HttpFoundation\ParameterBag $feedback
   *   The feedback.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment entity, or NULL in case of an exception.
   *
   * @throws InvalidResponseException
   *   An exception thrown if response SHASign does not validate.
   * @throws DeclineException
   *   An exception thrown if payment has been declined.
   */
  private function processFeedback(ParameterBag $feedback) {
    $ecommercePaymentResponse = new EcommercePaymentResponse($feedback->all());

    // Load the payment entity created in
    // ECommerceOffsiteForm::buildConfigurationForm().
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($feedback->get('PAYMENT_ID'));

    $payment->setRemoteId($ecommercePaymentResponse->getParam('PAYID'));
    $payment->setRemoteState($ecommercePaymentResponse->getParam('STATUS'));
    $payment->save();

    // Validate response's SHASign.
    $passphrase = new Passphrase($this->configuration['sha_out']);
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);
    if (!$ecommercePaymentResponse->isValid($shaComposer)) {
      $payment->set('state', 'failed');
      $payment->save();
      throw new InvalidResponseException($this->t('The gateway response looks suspicious.'));
    }

    // Validate response's status.
    if (!$ecommercePaymentResponse->isSuccessful()) {
      $payment->set('state', 'failed');
      $payment->save();
      throw new DeclineException($this->t('Payment has been declined by the gateway (@error_code).', [
        '@error_code' => $ecommercePaymentResponse->getParam('NCERROR'),
      ]), $ecommercePaymentResponse->getParam('NCERROR'));
    }

    return $payment;
  }

  /**
   * Gets a feedback sent by Ingenico from HTTP request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\ParameterBag
   *   The Ingenico feedback.
   */
  private function getIngenicoFeedbackFromRequest(Request $request) {
    if ($request->getMethod() == 'POST') {
      $feedback = $request->request;
    }
    else {
      $feedback = $request->query;
    }

    return $feedback;
  }

  private function convertLanguageFromUiMapFromConfiguration($input) {
    $output = [];
    foreach ($input as $key => $value) {
      $output[] = $key . '|' . $value;
    }

    return implode("\n", $output);
  }

  private function convertLanguageFromUiMapFromUserInput($input) {
    $input = explode("\n", $input);
    $input = array_filter($input);
    $output = [];
    foreach ($input as $input_line) {
      $parts = explode('|', trim($input_line));
      if (count($parts) == 2) {
        $output[trim($parts[0])] = trim($parts[1]);
      }
    }

    return $output;
  }

  private function convertIngenicoBrandsFromConfiguration($input) {
    $output = [];
    foreach ($input as $item) {
      $output[] = $item['title'] . '|' . $item['PM'] . '|' . $item['BRAND'];
    }

    return implode("\n", $output);
  }

  private function convertIngenicoBrandsFromUserInput($input) {
    $input = explode("\n", $input);
    $input = array_filter($input);
    $output = [];
    foreach ($input as $input_line) {
      $parts = explode('|', trim($input_line));
      if (count($parts) == 3) {
        $output[] = [
          'title' => trim($parts[0]),
          'PM' => trim($parts[1]),
          'BRAND' => trim($parts[2]),
        ];
      }
    }

    return $output;
  }

}
