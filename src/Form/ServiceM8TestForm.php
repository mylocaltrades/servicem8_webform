<?php

namespace Drupal\servicem8_webform\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for testing ServiceM8 API.
 */
class ServiceM8TestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'servicem8_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['test_data'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test Quote Data'),
    ];

    $form['test_data']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#default_value' => 'Test',
      '#required' => TRUE,
    ];

    $form['test_data']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#default_value' => 'Customer',
    ];

    $form['test_data']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => 'test@example.com',
    ];

    $form['test_data']['mobile'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile Phone'),
      '#default_value' => '0400000000',
    ];

    $form['test_data']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Landline Phone'),
      '#default_value' => '0298765432',
    ];

    $form['test_data']['job_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Job Address'),
      '#default_value' => '123 Test Street, Sydney NSW 2000',
      '#maxlength' => 255,
    ];

    $form['test_data']['billing_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Billing Address'),
      '#default_value' => '456 Invoice Road, Melbourne VIC 3000',
      '#description' => $this->t('Leave empty to use job address for billing'),
      '#maxlength' => 255,
    ];

    $form['test_data']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Job Description'),
      '#default_value' => 'Test quote created from Drupal - Please provide a quote for general maintenance services.',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Test Quote'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
public function submitForm(array &$form, FormStateInterface $form_state) {
  $values = $form_state->getValues();
  $config = \Drupal::config('servicem8_webform.settings');
  $api_key = $config->get('servicem8_api_key');

  if (empty($api_key)) {
    $this->messenger()->addError($this->t('Please configure your API key first.'));
    return;
  }

  try {
    $client = \Drupal::httpClient();
    
    // Step 1: Create a unique company
    $company_name = trim($values['first_name'] . ' ' . $values['last_name']);
    $unique_name = $company_name . ' ' . date('His');
    
    $company_payload = [
      'name' => $unique_name,
      'first_name' => $values['first_name'],
      'last_name' => $values['last_name'],
      'email' => $values['email'],
      'mobile' => preg_replace('/[^0-9+]/', '', $values['mobile']),
      'phone' => preg_replace('/[^0-9+]/', '', $values['phone']),
      'billing_address' => !empty($values['billing_address']) ? $values['billing_address'] : $values['job_address'],
    ];

    $company_response = $client->post('https://api.servicem8.com/api_1.0/company.json', [
      'headers' => [
        'X-API-Key' => $api_key,
        'Content-Type' => 'application/json',
      ],
      'json' => $company_payload,
    ]);

    if ($company_response->getStatusCode() === 200) {
      $company_uuid = $company_response->getHeaderLine('x-record-uuid');
      
      // Step 2: Create the job
      $job_payload = [
        'status' => 'Quote',
        'company_uuid' => $company_uuid,
        'job_address' => $values['job_address'],
        'job_description' => $values['description'],
      ];

      $job_response = $client->post('https://api.servicem8.com/api_1.0/job.json', [
        'headers' => [
          'X-API-Key' => $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => $job_payload,
      ]);

      if ($job_response->getStatusCode() === 200) {
        $job_uuid = $job_response->getHeaderLine('x-record-uuid');
        
        // Step 3: Create the job contact
        $contact_payload = [
          'job_uuid' => $job_uuid,
          'first' => $values['first_name'],
          'last' => $values['last_name'],
          'email' => $values['email'],
          'mobile' => preg_replace('/[^0-9+]/', '', $values['mobile']),
          'phone' => preg_replace('/[^0-9+]/', '', $values['phone']),
          'type' => 'JOB',
        ];

        $contact_response = $client->post('https://api.servicem8.com/api_1.0/jobcontact.json', [
          'headers' => [
            'X-API-Key' => $api_key,
            'Content-Type' => 'application/json',
          ],
          'json' => $contact_payload,
        ]);

        if ($contact_response->getStatusCode() === 200) {
          $this->messenger()->addStatus($this->t('✅ Test quote created successfully!'));
          $this->messenger()->addStatus($this->t('Company: @name', ['@name' => $unique_name]));
          $this->messenger()->addStatus($this->t('Job UUID: @job_uuid', ['@job_uuid' => $job_uuid]));
        } else {
          $this->messenger()->addWarning($this->t('Job created but contact details couldn\'t be added.'));
        }
      }
    }
  }
  catch (\Exception $e) {
    $this->messenger()->addError($this->t('❌ Failed: @error', ['@error' => $e->getMessage()]));
    \Drupal::logger('servicem8_webform')->error('Test quote failed: @error', ['@error' => $e->getMessage()]);
  }
}

}