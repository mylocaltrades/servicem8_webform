<?php

namespace Drupal\servicem8_webform\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure ServiceM8 settings for this site.
 */
class ServiceM8SettingsForm extends ConfigFormBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'servicem8_webform_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['servicem8_webform.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('servicem8_webform.settings');

    // Status message section.
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
    ];

    $api_key = $config->get('servicem8_api_key');
    if (!empty($api_key)) {
      $masked_key = substr($api_key, 0, 4) . str_repeat('*', 20) . substr($api_key, -4);
      $form['status']['current_status'] = [
        '#markup' => '<div class="messages messages--status">' . 
          $this->t('API Key configured: @key', ['@key' => $masked_key]) . 
          '</div>',
      ];
    }
    else {
      $form['status']['current_status'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('No API key configured. Please enter your ServiceM8 credentials below.') . 
          '</div>',
      ];
    }

    // API Credentials section.
    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Credentials'),
      '#open' => TRUE,
      '#description' => $this->t('Find your API key in ServiceM8: Settings → Integrations → API'),
    ];

    $form['api']['servicem8_email'] = [
      '#type' => 'email',
      '#title' => $this->t('ServiceM8 Account Email'),
      '#description' => $this->t('The email address associated with your ServiceM8 account (for reference only).'),
      '#default_value' => $config->get('servicem8_email'),
      '#required' => FALSE,
    ];

    $form['api']['servicem8_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ServiceM8 API Key'),
      '#description' => $this->t('Your ServiceM8 API Key for private applications. Keep this secure!'),
      '#required' => TRUE,
      '#default_value' => $config->get('servicem8_api_key'),
      '#attributes' => [
        'autocomplete' => 'off',
      ],
      '#maxlength' => 255,
    ];

    // Test Connection section.
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Testing'),
      '#open' => TRUE,
    ];

    $form['test']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test API Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'test-result-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing connection...'),
        ],
      ],
      '#attributes' => [
        'class' => ['button--primary'],
      ],
    ];

    $form['test']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-result-wrapper'],
    ];

    // Default Settings section.
    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Job Settings'),
      '#open' => TRUE,
      '#description' => $this->t('These defaults can be overridden in individual webform handlers.'),
    ];

    $form['defaults']['default_job_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Job Status'),
      '#options' => [
        'Quote' => $this->t('Quote'),
        'Work Order' => $this->t('Work Order'),
        'Scheduled' => $this->t('Scheduled'),
        'In Progress' => $this->t('In Progress'),
        'Completed' => $this->t('Completed'),
      ],
      '#default_value' => $config->get('default_job_status') ?? 'Quote',
      '#description' => $this->t('The default status for new jobs created via webform submissions.'),
    ];

    $form['defaults']['enable_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable ServiceM8 notifications'),
      '#description' => $this->t('Send ServiceM8 native notifications when jobs are created.'),
      '#default_value' => $config->get('enable_notifications') ?? FALSE,
    ];

    // Advanced Settings section.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['cache_badges'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache badge data'),
      '#description' => $this->t('Cache ServiceM8 badge information to reduce API calls.'),
      '#default_value' => $config->get('cache_badges') ?? TRUE,
    ];

    $form['advanced']['cache_duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache duration'),
      '#options' => [
        '3600' => $this->t('1 hour'),
        '21600' => $this->t('6 hours'),
        '86400' => $this->t('24 hours'),
        '604800' => $this->t('1 week'),
      ],
      '#default_value' => $config->get('cache_duration') ?? '3600',
      '#states' => [
        'visible' => [
          ':input[name="cache_badges"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Global debug mode'),
      '#description' => $this->t('Enable detailed logging for all ServiceM8 handlers (can be overridden per handler).'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback for the test connection button.
   */
  public static function testConnection(array &$form, FormStateInterface $form_state) {
    $api_key = trim($form_state->getValue('servicem8_api_key'));

    if (empty($api_key)) {
      return [
        '#markup' => '<div class="messages messages--warning">' . 
          t('Please enter an API key to test the connection.') . 
          '</div>'
      ];
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->get('https://api.servicem8.com/api_1.0/company.json', [
        'headers' => [
          'X-API-Key' => $api_key,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
        $company_name = $data[0]['name'] ?? 'Unknown';
        $company_email = $data[0]['email'] ?? '';
        
        return [
          '#markup' => '<div class="messages messages--status">' . 
            '<strong>' . t('✅ Connection successful!') . '</strong><br>' .
            t('Company: @name', ['@name' => $company_name]) . '<br>' .
            (!empty($company_email) ? t('Email: @email', ['@email' => $company_email]) : '') . 
            '</div>'
        ];
      }
    }
    catch (RequestException $e) {
      $error_message = t('An unknown error occurred.');
      
      if ($e->hasResponse()) {
        $status_code = $e->getResponse()->getStatusCode();
        switch ($status_code) {
          case 401:
            $error_message = t('Authentication failed. Please check your API key.');
            break;
          case 403:
            $error_message = t('Access denied. The API key may not have the correct permissions.');
            break;
          case 429:
            $error_message = t('Rate limit exceeded. Please try again in a few minutes.');
            break;
          case 404:
            $error_message = t('API endpoint not found. Please check ServiceM8 service status.');
            break;
          default:
            $error_message = t('Failed to connect. HTTP Status Code: @code', ['@code' => $status_code]);
        }
      }
      elseif (strpos($e->getMessage(), 'cURL error 28') !== FALSE) {
        $error_message = t('Connection timed out. Could not reach ServiceM8 servers.');
      }
      elseif (strpos($e->getMessage(), 'Could not resolve host') !== FALSE) {
        $error_message = t('Could not resolve ServiceM8 API host. Check your internet connection.');
      }
      
      return [
        '#markup' => '<div class="messages messages--error">' . 
          t('❌ Connection failed: @error', ['@error' => $error_message]) . 
          '</div>'
      ];
    }
    catch (\Exception $e) {
      return [
        '#markup' => '<div class="messages messages--error">' . 
          t('❌ Unexpected error: @message', ['@message' => $e->getMessage()]) . 
          '</div>'
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $api_key = $form_state->getValue('servicem8_api_key');
    
    // Validate API key format (basic check).
    if (!empty($api_key)) {
      // API keys are typically alphanumeric with hyphens.
      if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $api_key)) {
        $form_state->setErrorByName('servicem8_api_key', 
          $this->t('The API key contains invalid characters. Please check your API key.'));
      }
      
      // Check minimum length.
      if (strlen($api_key) < 10) {
        $form_state->setErrorByName('servicem8_api_key', 
          $this->t('The API key appears to be too short. Please enter a valid ServiceM8 API key.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('servicem8_webform.settings');
    
    // Save all settings.
    $config
      ->set('servicem8_email', $form_state->getValue('servicem8_email'))
      ->set('servicem8_api_key', $form_state->getValue('servicem8_api_key'))
      ->set('default_job_status', $form_state->getValue('default_job_status'))
      ->set('enable_notifications', $form_state->getValue('enable_notifications'))
      ->set('cache_badges', $form_state->getValue('cache_badges'))
      ->set('cache_duration', $form_state->getValue('cache_duration'))
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->save();

    // Clear cache if settings changed.
    if ($config->getOriginal('servicem8_api_key') !== $form_state->getValue('servicem8_api_key')) {
      \Drupal::cache()->invalidateTags(['servicem8_badges']);
      $this->messenger()->addStatus($this->t('ServiceM8 cache cleared due to API key change.'));
    }

    parent::submitForm($form, $form_state);
  }

}