<?php

namespace Drupal\servicem8_webform\Plugin\WebformHandler;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ServiceM8 Webform Handler.
 *
 * @WebformHandler(
 *   id = "servicem8",
 *   label = @Translation("ServiceM8 Job Creator"),
 *   category = @Translation("External"),
 *   description = @Translation("Sends webform submissions to ServiceM8 to create a new job."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class ServiceM8WebformHandler extends WebformHandlerBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $fileStorage;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Maximum file size in bytes (10MB).
   */
  const MAX_FILE_SIZE = 10485760;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->fileSystem = $container->get('file_system');
    $instance->renderer = $container->get('renderer');
    $instance->configFactory = $container->get('config.factory');
    $instance->cache = $container->get('cache.default');
    $instance->messenger = $container->get('messenger');
    $entityTypeManager = $container->get('entity_type.manager');
    $instance->fileStorage = $entityTypeManager->getStorage('file');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'use_global_settings' => TRUE,
      'override_api_email' => '',
      'override_api_key' => '',
      'override_job_status' => 'Quote',
      'mappings' => [],
      'custom_mappings' => '',
      'job_source_field_key' => '',
      'badge_mappings' => "facebook|Facebook Lead\ngoogle|Google Ads\nwebsite|Website Enquiry",
      'file_upload_field_key' => '',
      'success_message' => 'Thank you! Your request has been submitted successfully.',
      'error_message' => 'We apologize, but there was an error submitting your request. Please try again or contact us directly.',
      'send_notifications' => FALSE,
      'check_duplicates' => FALSE,
      'debug' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $all_element_options = $this->getWebformElementOptions();
    $file_element_options = $this->getWebformElementOptions(['managed_file', 'webform_document_file', 'webform_image_file']);
    $select_element_options = $this->getWebformElementOptions(['select', 'radios', 'checkboxes', 'webform_select_other']);

// Connection Settings Section.
    $form['use_global_settings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Global Settings'),
      '#description' => $this->t('Use the API key and default job status from the <a href="@url">global settings page</a>.', [
        '@url' => Url::fromRoute('servicem8_webform.settings')->toString(),
      ]),
      '#default_value' => $this->configuration['use_global_settings'],
    ];

    // Wrap ALL override fields in a fieldset
    $form['override_credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Override Credentials'),
      '#states' => [
        'invisible' => [
          ':input[name="settings[use_global_settings]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['override_credentials']['override_api_email'] = [
      '#type' => 'email',
      '#title' => $this->t('ServiceM8 Account Email'),
      '#description' => $this->t('For reference only - helps identify which account this connects to.'),
      '#default_value' => $this->configuration['override_api_email'],
    ];

    $form['override_credentials']['override_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['override_api_key'],
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['override_credentials']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testOverrideConnection',
        'wrapper' => 'override-test-result-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing connection...'),
        ],
      ],
      '#attributes' => [
        'class' => ['button--small'],
      ],
    ];

    $form['override_credentials']['test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'override-test-result-wrapper'],
    ];

    // Job Settings Section.
    $form['job_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Job Settings'),
      '#open' => TRUE,
    ];

    $form['job_settings']['override_job_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Job Status'),
      '#options' => [
        'Quote' => $this->t('Quote'),
        'Work Order' => $this->t('Work Order'),
        'Scheduled' => $this->t('Scheduled'),
        'In Progress' => $this->t('In Progress'),
        'Completed' => $this->t('Completed'),
      ],
      '#default_value' => $this->configuration['override_job_status'] ?? 'Quote',
      '#description' => $this->t('Status for jobs created by this form.'),
    ];

    $form['job_settings']['send_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send ServiceM8 notifications'),
      '#description' => $this->t('Trigger ServiceM8 native notifications when jobs are created.'),
      '#default_value' => $this->configuration['send_notifications'],
    ];

    $form['job_settings']['check_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check for duplicate contacts'),
      '#description' => $this->t('Search for existing contacts by email before creating new ones (recommended).'),
      '#default_value' => $this->configuration['check_duplicates'],
    ];

    // Field Mappings Section.
    $form['mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Mappings'),
      '#description' => $this->t('Map your webform fields to ServiceM8 job fields.'),
      '#open' => TRUE,
    ];

    // Required fields group.
    $form['mappings']['required_fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Required Fields'),
      '#description' => $this->t('At least one of these must be mapped.'),
    ];

$form['mappings']['required_fields']['mappings']['contact_first_name'] = [
  '#type' => 'select',
  '#title' => $this->t('First Name'),
  '#options' => $all_element_options,
  '#default_value' => isset($this->configuration['mappings']['contact_first_name']) ? $this->configuration['mappings']['contact_first_name'] : '',
];

$form['mappings']['required_fields']['mappings']['company_name'] = [
  '#type' => 'select',
  '#title' => $this->t('Company Name'),
  '#options' => $all_element_options,
  '#default_value' => isset($this->configuration['mappings']['company_name']) ? $this->configuration['mappings']['company_name'] : '',
];

    // Contact information group.
    $form['mappings']['contact_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contact Information'),
    ];

    $contact_fields = [
      'contact_last_name' => $this->t('Last Name'),
      'contact_email' => $this->t('Email Address'),
      'contact_mobile' => $this->t('Mobile Phone'),
      'contact_phone' => $this->t('Landline Phone'),
    ];

    foreach ($contact_fields as $key => $label) {
      $form['mappings']['contact_info']['mappings'][$key] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $all_element_options,
        '#default_value' => $this->configuration['mappings'][$key] ?? '',
      ];
    }

    // Job details group.
    $form['mappings']['job_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Job Details'),
    ];

    $job_fields = [
      'job_address' => $this->t('Job Address'),
      'description' => $this->t('Job Description'),
    ];

    foreach ($job_fields as $key => $label) {
      $form['mappings']['job_details']['mappings'][$key] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $all_element_options,
        '#default_value' => $this->configuration['mappings'][$key] ?? '',
      ];
    }

    $form['mappings']['custom_mappings'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Advanced Field Mappings'),
      '#description' => $this->t('Additional mappings, one per line: servicem8_field|webform_field<br>Example: job_is_scheduled|appointment_needed'),
      '#default_value' => $this->configuration['custom_mappings'],
      '#rows' => 4,
    ];

    // Badge Mapping Section.
    $form['badges'] = [
      '#type' => 'details',
      '#title' => $this->t('Lead Source Tracking'),
      '#open' => FALSE,
    ];

    $form['badges']['job_source_field_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Lead Source Field'),
      '#description' => $this->t('Select the form field that captures the lead source.'),
      '#options' => $select_element_options,
      '#default_value' => $this->configuration['job_source_field_key'],
    ];

    $form['badges']['badge_mappings'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Badge Mappings'),
      '#description' => $this->t('Map form values to ServiceM8 badges (badges must exist in ServiceM8).<br>Format: form_value|Badge Name<br>Example: facebook|Facebook Lead'),
      '#default_value' => $this->configuration['badge_mappings'],
      '#rows' => 5,
      '#states' => [
        'visible' => [
          ':input[name="settings[badges][job_source_field_key]"]' => ['!value' => ''],
        ],
      ],
    ];

    // File Attachments Section.
    $form['attachments'] = [
      '#type' => 'details',
      '#title' => $this->t('File Attachments'),
      '#open' => FALSE,
    ];

    $form['attachments']['file_upload_field_key'] = [
      '#type' => 'select',
      '#title' => $this->t('File Upload Field'),
      '#description' => $this->t('Files from this field will be attached to the ServiceM8 job. Max 10MB per file.'),
      '#options' => $file_element_options,
      '#default_value' => $this->configuration['file_upload_field_key'],
    ];

    // User Messages Section.
    $form['messages'] = [
      '#type' => 'details',
      '#title' => $this->t('User Messages'),
      '#open' => FALSE,
    ];

    $form['messages']['success_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Success Message'),
      '#description' => $this->t('Message shown to users after successful submission.'),
      '#default_value' => $this->configuration['success_message'],
      '#maxlength' => 255,
    ];

    $form['messages']['error_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Error Message'),
      '#description' => $this->t('Message shown if submission fails.'),
      '#default_value' => $this->configuration['error_message'],
      '#maxlength' => 255,
    ];

    // Development Section.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development & Debugging'),
      '#open' => FALSE,
    ];

    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#description' => $this->t('Log detailed information about submissions and API calls.'),
      '#default_value' => $this->configuration['debug'],
    ];

    return $this->setSettingsParents($form);
  }

  /**
   * AJAX callback for testing the connection.
   */
  public static function testOverrideConnection(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $api_key = trim($values['settings']['override_credentials']['override_api_key'] ?? '');

    if (empty($api_key)) {
      return [
        '#markup' => '<div class="messages messages--warning">' . 
          t('Please enter an API key to test the connection.') . 
          '</div>',
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
        $company = $data[0] ?? [];
        return [
          '#markup' => '<div class="messages messages--status">' . 
            '<strong>✅ ' . t('Connection successful!') . '</strong><br>' .
            t('Company: @name', ['@name' => $company['name'] ?? 'Unknown']) . 
            '</div>',
        ];
      }
    }
    catch (RequestException $e) {
      $error_message = t('Connection failed');
      if ($e->hasResponse()) {
        $status_code = $e->getResponse()->getStatusCode();
        switch ($status_code) {
          case 401:
            $error_message = t('Invalid API key');
            break;
          case 403:
            $error_message = t('API key lacks required permissions');
            break;
          case 429:
            $error_message = t('Rate limit exceeded. Try again later');
            break;
          default:
            $error_message = t('HTTP error @code', ['@code' => $status_code]);
        }
      }
      return [
        '#markup' => '<div class="messages messages--error">' . 
          '❌ ' . $error_message . 
          '</div>',
      ];
    }
    catch (\Exception $e) {
      return [
        '#markup' => '<div class="messages messages--error">' . 
          '❌ ' . t('Unexpected error occurred') . 
          '</div>',
      ];
    }
  }

/**
 * {@inheritdoc}
 */
public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  parent::validateConfigurationForm($form, $form_state);

  $values = $form_state->getValues();
  $use_global = !empty($values['use_global_settings']);
  
  // Only validate API key if NOT using global settings
  if (!$use_global) {
    $api_key = $values['override_api_key'] ?? '';
    if (empty($api_key)) {
      $form_state->setErrorByName('override_api_key', 
        $this->t('API Key is required when not using global settings.'));
    }
  }

  // Validate that at least one required field is mapped - CHECK THE NESTED STRUCTURE
  $first_name = $values['mappings']['required_fields']['mappings']['contact_first_name'] ?? '';
  $company_name = $values['mappings']['required_fields']['mappings']['company_name'] ?? '';
  
  if (empty($first_name) && empty($company_name)) {
    $form_state->setErrorByName('mappings', 
      $this->t('Either First Name or Company Name must be mapped.'));
  }
}

/**
 * {@inheritdoc}
 */
public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
  parent::submitConfigurationForm($form, $form_state);
  
  $values = $form_state->getValues();
  $flat_config = [];
  
  // Extract values from structure
  $flat_config['use_global_settings'] = $values['use_global_settings'] ?? TRUE;
  
  // Handle override credentials
  $flat_config['override_api_email'] = $values['override_api_email'] ?? '';
  $flat_config['override_api_key'] = $values['override_api_key'] ?? '';
  
  // Job settings
  $flat_config['override_job_status'] = $values['override_job_status'] ?? 'Quote';
  $flat_config['send_notifications'] = $values['send_notifications'] ?? FALSE;
  $flat_config['check_duplicates'] = $values['check_duplicates'] ?? FALSE;
  
  // PROPERLY EXTRACT MAPPINGS from nested structure
  $flat_config['mappings'] = [];
  
  // Get mappings from required fields section
  if (isset($values['mappings']['required_fields']['mappings'])) {
    foreach ($values['mappings']['required_fields']['mappings'] as $key => $value) {
      if (!empty($value)) {
        $flat_config['mappings'][$key] = $value;
      }
    }
  }
  
  // Get mappings from contact info section
  if (isset($values['mappings']['contact_info']['mappings'])) {
    foreach ($values['mappings']['contact_info']['mappings'] as $key => $value) {
      if (!empty($value)) {
        $flat_config['mappings'][$key] = $value;
      }
    }
  }
  
  // Get mappings from job details section
  if (isset($values['mappings']['job_details']['mappings'])) {
    foreach ($values['mappings']['job_details']['mappings'] as $key => $value) {
      if (!empty($value)) {
        $flat_config['mappings'][$key] = $value;
      }
    }
  }
  
  // Other fields
  $flat_config['custom_mappings'] = $values['mappings']['custom_mappings'] ?? '';
  $flat_config['job_source_field_key'] = $values['job_source_field_key'] ?? '';
  $flat_config['badge_mappings'] = $values['badge_mappings'] ?? '';
  $flat_config['file_upload_field_key'] = $values['file_upload_field_key'] ?? '';
  $flat_config['success_message'] = $values['success_message'] ?? '';
  $flat_config['error_message'] = $values['error_message'] ?? '';
  $flat_config['debug'] = $values['debug'] ?? FALSE;
  
  // Save the flattened configuration
  $this->configuration = $flat_config;
}

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Only process new submissions.
    if ($update) {
      return;
    }

    $api_key = $this->getApiKey();
    if (empty($api_key)) {
      $this->getLogger()->error('ServiceM8 API key not configured. Cannot process submission.');
      $this->showErrorMessage();
      return;
    }

    $values = $webform_submission->getData();
    $is_debug = $this->configuration['debug'] ?? FALSE;

    if ($is_debug) {
      $this->getLogger()->notice('Processing submission @sid', ['@sid' => $webform_submission->id()]);
    }

    try {
      // Build the payload.
      $payload = $this->buildPayload($values, $api_key);
      if (!$payload) {
        $this->showErrorMessage();
        return;
      }

      // Check for duplicates if enabled.
      if ($this->configuration['check_duplicates'] ?? FALSE) {
        $this->checkAndLinkExistingCompany($payload, $api_key);
      }

      // Create the job.
      $job_uuid = $this->createServiceM8Job($payload, $api_key, $is_debug);
      
      if ($job_uuid) {
        // Upload any attached files.
        $this->uploadFiles($job_uuid, $values, $api_key, $is_debug);
        
        // Show success message.
        $this->showSuccessMessage();
        
        // Store job UUID for reference.
        $webform_submission->setData(['servicem8_job_uuid' => $job_uuid] + $values);
        $webform_submission->save();
        
        if ($is_debug) {
          $this->getLogger()->notice('Created job @uuid for submission @sid', [
            '@uuid' => $job_uuid,
            '@sid' => $webform_submission->id(),
          ]);
        }
      }
      else {
        $this->showErrorMessage();
      }
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Unexpected error: @message', ['@message' => $e->getMessage()]);
      $this->showErrorMessage();
    }
  }

  /**
   * Gets the configured API key.
   */
  protected function getApiKey() {
    if ($this->configuration['use_global_settings'] ?? TRUE) {
      $global_config = $this->configFactory->get('servicem8_webform.settings');
      return $global_config->get('servicem8_api_key');
    }
    return $this->configuration['override_api_key'] ?? NULL;
  }

  /**
   * Gets the configured job status.
   */
  protected function getJobStatus() {
    if ($this->configuration['use_global_settings'] ?? TRUE) {
      $global_config = $this->configFactory->get('servicem8_webform.settings');
      return $global_config->get('default_job_status') ?? 'Quote';
    }
    return $this->configuration['override_job_status'] ?? 'Quote';
  }

/**
 * Builds the payload for ServiceM8.
 */
protected function buildPayload(array $values, string $api_key) {
  $payload = [
    'status' => $this->getJobStatus(),
  ];

  // Map standard fields with CORRECT ServiceM8 field names
  $field_mapping = [
    'contact_first_name' => 'contact_first',
    'contact_last_name' => 'contact_last',
    'company_name' => 'company_name',
    'contact_email' => 'contact_email',
    'contact_mobile' => 'contact_mobile',
    'contact_phone' => 'contact_phone',
    'job_address' => 'job_address',
    'description' => 'job_description',
  ];

  $mappings = $this->configuration['mappings'] ?? [];
  foreach ($mappings as $config_field => $webform_field) {
    if (!empty($webform_field) && isset($values[$webform_field])) {
      $value = $values[$webform_field];
      
      // Map to correct ServiceM8 field name
      $sm8_field = $field_mapping[$config_field] ?? $config_field;
      
      // Clean phone numbers
      if (in_array($sm8_field, ['contact_mobile', 'contact_phone'])) {
        $value = preg_replace('/[^0-9+]/', '', $value);
      }
      
      $payload[$sm8_field] = $value;
    }
  }

  // Process custom mappings
  if (!empty($this->configuration['custom_mappings'])) {
    $lines = preg_split("/\r\n|\n|\r/", $this->configuration['custom_mappings']);
    foreach ($lines as $line) {
      if (strpos($line, '|') !== FALSE) {
        list($sm8_field, $webform_field) = array_map('trim', explode('|', $line, 2));
        if (isset($values[$webform_field])) {
          $payload[$sm8_field] = $values[$webform_field];
        }
      }
    }
  }

  // Validate required fields
  if (empty($payload['contact_first']) && empty($payload['company_name'])) {
    $this->getLogger()->error('Missing required field: First name or company name required.');
    return NULL;
  }

  // Add badges
  $this->addBadgeToPayload($payload, $values, $api_key);

  return $payload;
}

  /**
   * Checks for existing company and links if found.
   */
  protected function checkAndLinkExistingCompany(array &$payload, string $api_key) {
    if (empty($payload['contact_email'])) {
      return;
    }

    try {
      $search_url = 'https://api.servicem8.com/api_1.0/company.json?' . http_build_query([
        '$filter' => "email eq '" . $payload['contact_email'] . "'",
      ]);
      
      $response = $this->httpClient->get($search_url, [
        'headers' => ['X-API-Key' => $api_key],
      ]);
      
      $companies = json_decode($response->getBody()->getContents(), TRUE);
      if (!empty($companies[0]['uuid'])) {
        $payload['company_uuid'] = $companies[0]['uuid'];
      }
    }
    catch (\Exception $e) {
      // Continue without linking if search fails.
    }
  }

  /**
   * Adds badge to payload based on mapping.
   */
  protected function addBadgeToPayload(array &$payload, array $values, string $api_key) {
    $source_field = $this->configuration['job_source_field_key'] ?? '';
    if (empty($source_field) || empty($values[$source_field])) {
      return;
    }

    $source_value = $values[$source_field];
    $badge_mappings = $this->configuration['badge_mappings'] ?? '';
    $badge_name = NULL;

    // Find the badge name from mappings.
    $lines = preg_split("/\r\n|\n|\r/", $badge_mappings);
    foreach ($lines as $line) {
      if (strpos($line, '|') !== FALSE) {
        list($value, $name) = array_map('trim', explode('|', $line, 2));
        if ($value === $source_value) {
          $badge_name = $name;
          break;
        }
      }
    }

    if (!$badge_name) {
      return;
    }

    // Get badges from cache or API.
    $badges = $this->getCachedBadges($api_key);
    foreach ($badges as $badge) {
      if (($badge['name'] ?? '') === $badge_name) {
        $payload['badges'][] = $badge['uuid'];
        break;
      }
    }
  }

  /**
   * Gets badges with caching.
   */
  protected function getCachedBadges(string $api_key) {
    $cid = 'servicem8_badges:' . md5($api_key);
    $cache = $this->cache->get($cid);
    
    if ($cache && $cache->data) {
      return $cache->data;
    }

    try {
      $response = $this->httpClient->get('https://api.servicem8.com/api_1.0/badge.json', [
        'headers' => ['X-API-Key' => $api_key],
      ]);
      $badges = json_decode($response->getBody()->getContents(), TRUE);
      
      // Cache for 1 hour.
      $expire = time() + 3600;
      $this->cache->set($cid, $badges, $expire, ['servicem8_badges']);
      
      return $badges;
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to fetch badges: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

/**
 * Creates a job in ServiceM8.
 */
protected function createServiceM8Job(array $payload, string $api_key, bool $is_debug) {
  try {
    // Step 1: Create or find the company
    $company_uuid = null;
    
    // Check for existing company if duplicate checking is enabled
    if (($this->configuration['check_duplicates'] ?? FALSE) && !empty($payload['contact_email'])) {
      // Try to find existing company by email
      $companies = $this->searchCompanyByEmail($payload['contact_email'], $api_key);
      if (!empty($companies[0]['uuid'])) {
        $company_uuid = $companies[0]['uuid'];
        if ($is_debug) {
          $this->getLogger()->notice('Using existing company: @uuid', ['@uuid' => $company_uuid]);
        }
      }
    }
    
    // Create new company if not found
    if (!$company_uuid) {
      $company_name = trim(
        ($payload['contact_first'] ?? '') . ' ' . 
        ($payload['contact_last'] ?? $payload['company_name'] ?? 'Unknown')
      );
      
      $company_payload = [
        'name' => $company_name,
        'first_name' => $payload['contact_first'] ?? '',
        'last_name' => $payload['contact_last'] ?? '',
        'email' => $payload['contact_email'] ?? '',
        'mobile' => $payload['contact_mobile'] ?? '',
        'phone' => $payload['contact_phone'] ?? '',
        'billing_address' => $payload['billing_address'] ?? $payload['job_address'] ?? '',
      ];
      
      // Try to create the company, handling duplicate name errors
      try {
        $company_response = $this->httpClient->post('https://api.servicem8.com/api_1.0/company.json', [
          'headers' => [
            'X-API-Key' => $api_key,
            'Content-Type' => 'application/json',
          ],
          'json' => $company_payload,
          'timeout' => 30,
        ]);
        
        $company_uuid = $company_response->getHeaderLine('x-record-uuid');
      }
      catch (RequestException $e) {
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400) {
          $body = json_decode($e->getResponse()->getBody()->getContents(), TRUE);
          if (strpos($body['message'] ?? '', 'Name must be unique') !== FALSE) {
            // Name exists, try to find the existing company by name
            $companies = $this->searchCompanyByName($company_name, $api_key);
            if (!empty($companies[0]['uuid'])) {
              $company_uuid = $companies[0]['uuid'];
              if ($is_debug) {
                $this->getLogger()->notice('Found existing company by name: @uuid', ['@uuid' => $company_uuid]);
              }
            } else {
              // Add timestamp to make name unique
              $company_payload['name'] = $company_name . ' (' . date('Y-m-d H:i') . ')';
              $company_response = $this->httpClient->post('https://api.servicem8.com/api_1.0/company.json', [
                'headers' => [
                  'X-API-Key' => $api_key,
                  'Content-Type' => 'application/json',
                ],
                'json' => $company_payload,
                'timeout' => 30,
              ]);
              $company_uuid = $company_response->getHeaderLine('x-record-uuid');
              if ($is_debug) {
                $this->getLogger()->notice('Created company with unique name: @name', ['@name' => $company_payload['name']]);
              }
            }
          } else {
            throw $e; // Re-throw if it's a different error
          }
        } else {
          throw $e;
        }
      }
      
      if ($is_debug && $company_uuid) {
        $this->getLogger()->notice('Company ready: @uuid', ['@uuid' => $company_uuid]);
      }
    }
    
    // Step 2: Create the job
    $job_payload = [
      'status' => $payload['status'] ?? $this->getJobStatus(),
      'company_uuid' => $company_uuid,
      'job_address' => $payload['job_address'] ?? '',
      'job_description' => $payload['job_description'] ?? $payload['description'] ?? '',
    ];
    
    // Add badges if any - ServiceM8 expects JSON-encoded string
    if (!empty($payload['badges'])) {
      $job_payload['badges'] = json_encode($payload['badges']);
    }
    
    $job_response = $this->httpClient->post('https://api.servicem8.com/api_1.0/job.json', [
      'headers' => [
        'X-API-Key' => $api_key,
        'Content-Type' => 'application/json',
      ],
      'json' => $job_payload,
      'timeout' => 30,
    ]);
    
    $job_uuid = $job_response->getHeaderLine('x-record-uuid');
    
    // Step 3: Create job contact
    $contact_payload = [
      'job_uuid' => $job_uuid,
      'first' => $payload['contact_first'] ?? '',
      'last' => $payload['contact_last'] ?? '',
      'email' => $payload['contact_email'] ?? '',
      'mobile' => $payload['contact_mobile'] ?? '',
      'phone' => $payload['contact_phone'] ?? '',
      'type' => 'JOB',
    ];
    
    $contact_response = $this->httpClient->post('https://api.servicem8.com/api_1.0/jobcontact.json', [
      'headers' => [
        'X-API-Key' => $api_key,
        'Content-Type' => 'application/json',
      ],
      'json' => $contact_payload,
      'timeout' => 30,
    ]);
    
    if ($is_debug) {
      $this->getLogger()->notice('Job created: @uuid with contact', ['@uuid' => $job_uuid]);
    }
    
    return $job_uuid;
    
  }
  catch (RequestException $e) {
    if ($e->hasResponse()) {
      $status_code = $e->getResponse()->getStatusCode();
      if ($status_code === 429) {
        $this->getLogger()->error('ServiceM8 rate limit exceeded.');
        $this->messenger->addError($this->t('System is busy. Please try again in a few minutes.'));
      }
      else {
        $this->getLogger()->error('API error @code: @body', [
          '@code' => $status_code,
          '@body' => $e->getResponse()->getBody()->getContents(),
        ]);
      }
    }
    else {
      $this->getLogger()->error('Request failed: @message', ['@message' => $e->getMessage()]);
    }
  }
  catch (\Exception $e) {
    $this->getLogger()->error('Unexpected error: @message', ['@message' => $e->getMessage()]);
  }
  
  return NULL;
}

/**
 * Search for company by name.
 */
protected function searchCompanyByName($name, $api_key) {
  try {
    $page_size = 100;
    $page = 0;
    
    do {
      $response = $this->httpClient->get('https://api.servicem8.com/api_1.0/company.json', [
        'headers' => ['X-API-Key' => $api_key],
        'query' => [
          '$top' => $page_size,
          '$skip' => $page * $page_size,
        ],
        'timeout' => 10,
      ]);
      
      $companies = json_decode($response->getBody()->getContents(), TRUE);
      
      foreach ($companies as $company) {
        if (isset($company['name']) && strcasecmp($company['name'], $name) === 0) {
          return [$company];
        }
      }
      
      $page++;
    } while (count($companies) === $page_size);
    
    return [];
  }
  catch (\Exception $e) {
    return [];
  }
}

/**
 * Search for company by email.
 */
protected function searchCompanyByEmail($email, $api_key) {
  try {
    // More efficient: search by email in smaller batches
    $page_size = 100;
    $page = 0;
    
    do {
      $response = $this->httpClient->get('https://api.servicem8.com/api_1.0/company.json', [
        'headers' => ['X-API-Key' => $api_key],
        'query' => [
          '$top' => $page_size,
          '$skip' => $page * $page_size,
        ],
        'timeout' => 10,
      ]);
      
      $companies = json_decode($response->getBody()->getContents(), TRUE);
      
      foreach ($companies as $company) {
        if (isset($company['email']) && strcasecmp($company['email'], $email) === 0) {
          return [$company]; // Found match, return immediately
        }
      }
      
      $page++;
    } while (count($companies) === $page_size);
    
    return []; // No match found
  }
  catch (\Exception $e) {
    return [];
  }
}

/**
 * Uploads files to ServiceM8 job.
 */
protected function uploadFiles(string $job_uuid, array $values, string $api_key, bool $is_debug) {
  $file_field = $this->configuration['file_upload_field_key'] ?? '';
  if (empty($file_field) || empty($values[$file_field])) {
    return;
  }

  $fids = is_array($values[$file_field]) ? $values[$file_field] : [$values[$file_field]];
  
  foreach ($fids as $fid) {
    $file = $this->fileStorage->load($fid);
    if (!$file) {
      continue;
    }

    // Check file size.
    if ($file->getSize() > self::MAX_FILE_SIZE) {
      $this->getLogger()->warning('File @name exceeds size limit', [
        '@name' => $file->getFilename(),
      ]);
      continue;
    }

    try {
      $filename = $file->getFilename();
      $file_extension = '.' . pathinfo($filename, PATHINFO_EXTENSION);
      
      // Step 1: Create the attachment record
      $attachment_data = [
        'related_object' => 'job',
        'related_object_uuid' => $job_uuid,
        'attachment_name' => $filename,
        'file_type' => strtolower($file_extension), // Ensure lowercase
        'active' => true,
      ];
      
      $attachment_response = $this->httpClient->post('https://api.servicem8.com/api_1.0/Attachment.json', [
        'headers' => [
          'X-API-Key' => $api_key,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => $attachment_data,
      ]);
      
      $attachment_uuid = $attachment_response->getHeaderLine('x-record-uuid');
      
      if (empty($attachment_uuid)) {
        $this->getLogger()->error('No attachment UUID returned for @name', [
          '@name' => $filename,
        ]);
        continue;
      }
      
      // Step 2: Upload the actual file data - using CURLFile approach
      $file_path = $this->fileSystem->realpath($file->getFileUri());
      
      if (!file_exists($file_path) || !is_readable($file_path)) {
        $this->getLogger()->error('File not found or not readable: @path', ['@path' => $file_path]);
        continue;
      }
      
      // Use curl directly for file upload to match ServiceM8's example
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "https://api.servicem8.com/api_1.0/Attachment/{$attachment_uuid}.file");
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $api_key,
        'Accept: application/json',
      ]);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new \CURLFile($file_path, $file->getMimeType(), $filename)]);
      
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      
      if ($http_code !== 200) {
        throw new \Exception("Upload failed with HTTP {$http_code}: {$response}");
      }
      
      if ($is_debug) {
        $this->getLogger()->notice('Uploaded file @name to job @uuid', [
          '@name' => $filename,
          '@uuid' => $job_uuid,
        ]);
      }
      
    } catch (\Exception $e) {
      $this->getLogger()->error('Failed to upload @name: @message', [
        '@name' => $file->getFilename(),
        '@message' => $e->getMessage(),
      ]);
    }
  }
}

  /**
   * Shows success message to user.
   */
  protected function showSuccessMessage() {
    $message = $this->configuration['success_message'] ?? 
      $this->t('Thank you! Your request has been submitted successfully.');
    $this->messenger->addStatus($message);
  }

  /**
   * Shows error message to user.
   */
  protected function showErrorMessage() {
    $message = $this->configuration['error_message'] ?? 
      $this->t('We apologize, but there was an error submitting your request. Please try again or contact us directly.');
    $this->messenger->addError($message);
  }

  /**
   * Gets webform element options for select lists.
   */
  protected function getWebformElementOptions(array $types = []) {
    $webform = $this->getWebform();
    $elements = $webform->getElementsDecodedAndFlattened();
    $options = ['' => $this->t('- None -')];

    foreach ($elements as $key => $element) {
      $type_match = empty($types) || (isset($element['#type']) && in_array($element['#type'], $types));
      $not_composite = !isset($element['#webform_composite_elements']);
      
      if ($type_match && $not_composite) {
        $title = $element['#title'] ?? $key;
        $options[$key] = $title . ' (' . $key . ')';
      }
    }

    return $options;
  }

}