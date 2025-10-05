<?php

namespace Drupal\servicem8_webform\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides help and documentation for ServiceM8 integration.
 */
class ServiceM8HelpController extends ControllerBase {

  /**
   * Displays the ServiceM8 field reference page.
   */
  public function fieldReference() {
    $build = [];

    $build['intro'] = [
      '#markup' => '<h2>' . $this->t('ServiceM8 Field Reference') . '</h2>' .
        '<p>' . $this->t('This page documents all available ServiceM8 fields you can map in your webform handlers.') . '</p>',
    ];

    // Company Fields (for creating the client/company record)
    $build['company_fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Company/Client Fields'),
      '#open' => TRUE,
      '#description' => $this->t('These fields are used when creating the company/client record in ServiceM8.'),
    ];

    $company_fields = [
      'name' => ['Company Name', 'Auto-generated from first + last name', 'Text'],
      'first_name' => ['First Name', 'Required if no company name', 'Text'],
      'last_name' => ['Last Name', 'Optional', 'Text'],
      'email' => ['Email Address', 'Recommended', 'Email'],
      'mobile' => ['Mobile Phone', 'Optional', 'Phone (numbers only)'],
      'phone' => ['Landline Phone', 'Optional', 'Phone (numbers only)'],
      'billing_address' => ['Billing Address', 'Optional', 'Text'],
    ];

    $rows = [];
    foreach ($company_fields as $key => $info) {
      $rows[] = [
        'field' => '<code>' . $key . '</code>',
        'label' => $info[0],
        'required' => $info[1],
        'type' => $info[2],
      ];
    }

    $build['company_fields']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ServiceM8 Field'),
        $this->t('Description'),
        $this->t('Required'),
        $this->t('Type'),
      ],
      '#rows' => $rows,
    ];

    // Job Fields
    $build['job_fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Job Fields'),
      '#open' => TRUE,
      '#description' => $this->t('These fields are used when creating the job/quote record.'),
    ];

    $job_fields = [
      'status' => ['Job Status', 'Required', 'Quote/Work Order/etc'],
      'company_uuid' => ['Company UUID', 'Auto-linked', 'UUID (automatic)'],
      'job_address' => ['Job Address', 'Recommended', 'Full address text'],
      'job_description' => ['Job Description', 'Optional', 'Text/Textarea'],
    ];

    $rows = [];
    foreach ($job_fields as $key => $info) {
      $rows[] = [
        'field' => '<code>' . $key . '</code>',
        'label' => $info[0],
        'required' => $info[1],
        'type' => $info[2],
      ];
    }

    $build['job_fields']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ServiceM8 Field'),
        $this->t('Description'),
        $this->t('Required'),
        $this->t('Type'),
      ],
      '#rows' => $rows,
    ];

    // Job Contact Fields
    $build['contact_fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Job Contact Fields'),
      '#open' => TRUE,
      '#description' => $this->t('These fields are used for the job contact (auto-created with each job).'),
    ];

    $contact_fields = [
      'job_uuid' => ['Job UUID', 'Auto-linked', 'UUID (automatic)'],
      'first' => ['First Name', 'From company record', 'Text'],
      'last' => ['Last Name', 'From company record', 'Text'],
      'email' => ['Email', 'From company record', 'Email'],
      'mobile' => ['Mobile', 'From company record', 'Phone'],
      'phone' => ['Phone', 'From company record', 'Phone'],
      'type' => ['Contact Type', 'Auto-set to "JOB"', 'Fixed value'],
    ];

    $rows = [];
    foreach ($contact_fields as $key => $info) {
      $rows[] = [
        'field' => '<code>' . $key . '</code>',
        'label' => $info[0],
        'required' => $info[1],
        'type' => $info[2],
      ];
    }

    $build['contact_fields']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ServiceM8 Field'),
        $this->t('Description'),
        $this->t('Required'),
        $this->t('Type'),
      ],
      '#rows' => $rows,
    ];

    // Mapping Guide
    $build['mapping_guide'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Mapping in Your Handler'),
      '#open' => TRUE,
    ];

    $build['mapping_guide']['content'] = [
      '#markup' => '<p><strong>' . $this->t('Important:') . '</strong> ' . 
        $this->t('The handler configuration uses friendly names that get mapped to the correct ServiceM8 field names:') . '</p>' .
        '<ul>' .
        '<li>' . $this->t('First Name → <code>contact_first</code>') . '</li>' .
        '<li>' . $this->t('Last Name → <code>contact_last</code>') . '</li>' .
        '<li>' . $this->t('Job Description → <code>job_description</code>') . '</li>' .
        '</ul>' .
        '<p>' . $this->t('The handler automatically handles this mapping for you.') . '</p>',
    ];

    // Testing Section
    $build['testing'] = [
      '#type' => 'details',
      '#title' => $this->t('Testing Your Integration'),
      '#open' => TRUE,
    ];

    $build['testing']['content'] = [
      '#markup' => '<ol>' .
        '<li>' . $this->t('Use the <a href="@url">Test API</a> tab to test your connection', ['@url' => '/admin/config/services/servicem8/test']) . '</li>' .
        '<li>' . $this->t('Enable debug mode in your handler to see the exact payload being sent') . '</li>' .
        '<li>' . $this->t('Check Drupal logs at Reports → Recent log messages') . '</li>' .
        '</ol>',
    ];

    // API Flow
    $build['api_flow'] = [
      '#type' => 'details',
      '#title' => $this->t('How It Works'),
      '#open' => FALSE,
    ];

    $build['api_flow']['content'] = [
      '#markup' => '<p>' . $this->t('When a form is submitted, the handler:') . '</p>' .
        '<ol>' .
        '<li>' . $this->t('Creates a company/client record (or finds existing by email)') . '</li>' .
        '<li>' . $this->t('Creates a job/quote linked to that company') . '</li>' .
        '<li>' . $this->t('Creates a job contact record with contact details') . '</li>' .
        '<li>' . $this->t('Uploads any file attachments to the job') . '</li>' .
        '</ol>',
    ];

    return $build;
  }
/**
 * API Test page.
 */
public function apiTest() {
  // Build the form properly
  $build = [];
  $build['form'] = \Drupal::formBuilder()->getForm('Drupal\servicem8_webform\Form\ServiceM8TestForm');
  return $build;
}

}