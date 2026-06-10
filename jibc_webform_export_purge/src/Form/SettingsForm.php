<?php

namespace Drupal\jibc_webform_export_purge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for JIBC Webform Export & Purge.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['jibc_webform_export_purge.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'jibc_webform_export_purge_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('jibc_webform_export_purge.settings');

    // --- Status & Toggle ---
    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Status'),
    ];

    $form['status']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable weekly export & purge'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('When enabled, submissions will be exported via email and purged on the scheduled day during cron.'),
    ];

    $last_export = $config->get('last_export');
    if ($last_export) {
      $form['status']['last_export_info'] = [
        '#markup' => '<p><strong>' . $this->t('Last export:') . '</strong> ' . date('Y-m-d H:i:s T', $last_export) . '</p>',
      ];
    }
    else {
      $form['status']['last_export_info'] = [
        '#markup' => '<p><em>' . $this->t('No exports have been run yet.') . '</em></p>',
      ];
    }

    // --- Email Settings ---
    $form['email'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Settings'),
    ];

    $form['email']['email_to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient email address(es)'),
      '#default_value' => $config->get('email_to'),
      '#description' => $this->t('Email address to send the CSV export to. Separate multiple addresses with commas (e.g., <em>communications@jibc.ca, admin@jibc.ca</em>).'),
      '#required' => TRUE,
      '#maxlength' => 1024,
    ];

    $form['email']['email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email subject'),
      '#default_value' => $config->get('email_subject') ?: 'Weekly Webform Submissions Export',
      '#description' => $this->t('Use <code>[webform_id]</code> as a placeholder for the webform machine name.'),
      '#required' => TRUE,
    ];

    $form['email']['email_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email body'),
      '#default_value' => $config->get('email_body') ?: 'Attached is the weekly export of webform submissions.',
      '#description' => $this->t('Plain text email body. Use <code>[webform_id]</code> as a placeholder. Submission count and date will be appended automatically.'),
      '#rows' => 4,
    ];

    // --- Webform Selection ---
    $form['webforms'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Webforms'),
    ];

    $webform_options = [];
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple();
    foreach ($webforms as $webform) {
      $submission_count = $this->getSubmissionCount($webform->id());
      $webform_options[$webform->id()] = $webform->label() . ' (' . $webform->id() . ') — ' . $submission_count . ' submissions';
    }

    if (empty($webform_options)) {
      $form['webforms']['no_webforms'] = [
        '#markup' => '<p>' . $this->t('No webforms found. Please create a webform first.') . '</p>',
      ];
    }
    else {
      $form['webforms']['webform_ids'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select webforms to export'),
        '#options' => $webform_options,
        '#default_value' => $config->get('webform_ids') ?: [],
        '#description' => $this->t('Submissions for selected webforms will be exported as CSV and emailed, then purged.'),
      ];
    }

    // --- Schedule ---
    $form['schedule'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Schedule'),
    ];

    $form['schedule']['export_day'] = [
      '#type' => 'select',
      '#title' => $this->t('Export day'),
      '#options' => [
        'monday' => $this->t('Monday'),
        'tuesday' => $this->t('Tuesday'),
        'wednesday' => $this->t('Wednesday'),
        'thursday' => $this->t('Thursday'),
        'friday' => $this->t('Friday'),
        'saturday' => $this->t('Saturday'),
        'sunday' => $this->t('Sunday'),
      ],
      '#default_value' => $config->get('export_day') ?: 'monday',
      '#description' => $this->t('Day of the week to run the export during cron. The export runs on the first cron execution of that day.'),
    ];

    // --- Manual Run ---
    $form['manual'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Manual Export'),
    ];

    $form['manual']['run_now'] = [
      '#type' => 'link',
      '#title' => $this->t('Run export now'),
      '#url' => Url::fromRoute('jibc_webform_export_purge.manual_export'),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
    ];

    $form['manual']['run_warning'] = [
      '#markup' => '<div class="messages messages--warning" style="margin-top: 10px;">' .
        $this->t('<strong>Warning:</strong> This will immediately export and <strong>purge all submissions</strong> for the selected webforms, regardless of the scheduled day. This cannot be undone.') .
        '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate email addresses.
    $emails = array_map('trim', explode(',', $form_state->getValue('email_to')));
    foreach ($emails as $email) {
      if (!empty($email) && !\Drupal::service('email.validator')->isValid($email)) {
        $form_state->setErrorByName('email_to', $this->t('Invalid email address: @email', ['@email' => $email]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Filter out unchecked webforms.
    $webform_ids_raw = $form_state->getValue('webform_ids');
    $webform_ids = is_array($webform_ids_raw)
      ? array_values(array_filter($webform_ids_raw))
      : [];

    $this->config('jibc_webform_export_purge.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('email_to', trim($form_state->getValue('email_to')))
      ->set('email_subject', $form_state->getValue('email_subject'))
      ->set('email_body', $form_state->getValue('email_body'))
      ->set('webform_ids', $webform_ids)
      ->set('export_day', $form_state->getValue('export_day'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get the current submission count for a webform.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return int
   *   Number of submissions.
   */
  protected function getSubmissionCount(string $webform_id): int {
    return (int) $this->entityTypeManager->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $webform_id)
      ->count()
      ->execute();
  }

}
