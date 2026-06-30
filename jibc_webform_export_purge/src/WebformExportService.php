<?php

namespace Drupal\jibc_webform_export_purge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Service for exporting webform submissions via email and purging them.
 */
class WebformExportService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a WebformExportService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time,
    FileSystemInterface $file_system,
    \Drupal\path_alias\AliasManagerInterface $alias_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->logger = $logger_factory->get('jibc_webform_export_purge');
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->fileSystem = $file_system;
    $this->aliasManager = $alias_manager;
  }

  /**
   * Export submissions for all configured webforms and email as CSV.
   *
   * @param array $webform_ids
   *   Array of webform machine names.
   * @param string $email_to
   *   Recipient email address(es), comma-separated.
   */
  public function exportAndPurge(array $webform_ids, string $email_to): void {
    $config = $this->configFactory->get('jibc_webform_export_purge.settings');
    $subject = $config->get('email_subject') ?: 'Weekly Webform Submissions Export';
    $body = $config->get('email_body') ?: 'Attached is the weekly export of webform submissions.';

    $total_exported = 0;
    $total_purged = 0;

    foreach ($webform_ids as $webform_id) {
      $result = $this->processWebform($webform_id, $email_to, $subject, $body);
      $total_exported += $result['exported'];
      $total_purged += $result['purged'];
    }

    // Update last export timestamp.
    $this->configFactory->getEditable('jibc_webform_export_purge.settings')
      ->set('last_export', $this->time->getRequestTime())
      ->save();

    $this->logger->info('Export complete. Total exported: @exported, purged: @purged.', [
      '@exported' => $total_exported,
      '@purged' => $total_purged,
    ]);
  }

  /**
   * Process a single webform: build CSV, email it, then purge.
   *
   * @param string $webform_id
   *   The webform machine name.
   * @param string $email_to
   *   Recipient email address(es).
   * @param string $subject
   *   Email subject line.
   * @param string $body
   *   Email body text.
   *
   * @return array
   *   Counts: ['exported' => int, 'purged' => int].
   */
  protected function processWebform(string $webform_id, string $email_to, string $subject, string $body): array {
    $storage = $this->entityTypeManager->getStorage('webform_submission');

    // Load all submission IDs for this webform.
    $sids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $webform_id)
      ->sort('created', 'ASC')
      ->execute();

    if (empty($sids)) {
      $this->logger->info('No submissions found for webform @id. Skipping.', ['@id' => $webform_id]);
      return ['exported' => 0, 'purged' => 0];
    }

    $this->logger->info('Found @count submissions for webform @id.', [
      '@count' => count($sids),
      '@id' => $webform_id,
    ]);

    // Load all submissions.
    $submissions = $storage->loadMultiple($sids);

    // Build CSV content.
    $csv_content = $this->buildCsv($webform_id, $submissions);

    if (empty($csv_content)) {
      $this->logger->error('Failed to generate CSV for webform @id.', ['@id' => $webform_id]);
      return ['exported' => 0, 'purged' => 0];
    }

    // Write CSV to a temp file. The SMTP module and SendGrid module
    // both support reading attachments from file paths.
    $date_stamp = date('Y-m-d');
    $filename = "{$webform_id}_submissions_{$date_stamp}.csv";
    $temp_path = $this->fileSystem->getTempDirectory() . '/' . $filename;

    if (file_put_contents($temp_path, $csv_content) === FALSE) {
      $this->logger->error('Failed to write temp CSV file for webform @id.', ['@id' => $webform_id]);
      return ['exported' => 0, 'purged' => 0];
    }

    // Customize subject/body with webform info.
    $token_subject = str_replace('[webform_id]', $webform_id, $subject);
    $token_body = str_replace('[webform_id]', $webform_id, $body);
    $token_body .= "\n\nWebform: {$webform_id}";
    $token_body .= "\nSubmissions: " . count($submissions);
    $token_body .= "\nExport date: {$date_stamp}";

    // Send the email with CSV attachment.
    $success = $this->sendEmail($email_to, $token_subject, $token_body, $temp_path, $filename, $csv_content);

    // Clean up temp file regardless of outcome.
    @unlink($temp_path);

    if ($success) {
      // Purge submissions only after successful email send.
      $this->purgeSubmissions($submissions);
      $this->logger->info('Emailed and purged @count submissions for webform @id.', [
        '@count' => count($submissions),
        '@id' => $webform_id,
      ]);
      return ['exported' => count($submissions), 'purged' => count($submissions)];
    }
    else {
      $this->logger->error('Email send FAILED for webform @id. @count submissions preserved for retry.', [
        '@count' => count($submissions),
        '@id' => $webform_id,
      ]);
      return ['exported' => 0, 'purged' => 0];
    }
  }

  /**
   * Build CSV content from webform submissions.
   *
   * Dynamically detects all field names across all submissions to build
   * a complete header row. Handles submissions that may have different
   * fields filled in.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param array $submissions
   *   Array of WebformSubmission entities.
   *
   * @return string
   *   The CSV file content as a string.
   */
  protected function buildCsv(string $webform_id, array $submissions): string {
    if (empty($submissions)) {
      return '';
    }

    // Collect all unique field keys across every submission.
    $all_field_keys = [];
    $rows = [];

    foreach ($submissions as $submission) {
      $data = $submission->getData();

      // Flatten any nested arrays (e.g., composite fields) into strings.
      $flat_data = $this->flattenData($data);

      foreach (array_keys($flat_data) as $key) {
        $all_field_keys[$key] = TRUE;
      }

      $rows[] = [
        'sid' => $submission->id(),
        'serial' => $submission->serial(),
        'created' => date('Y-m-d H:i:s', $submission->getCreatedTime()),
        'remote_addr' => $submission->getRemoteAddr(),
        'uri' => $submission->get('uri')->value ?? '',
        'page_title' => $this->getSourcePageTitle($submission->get('uri')->value ?? ''),
        'data' => $flat_data,
      ];
    }

    // Build header: metadata columns + all webform field columns.
    $meta_columns = ['sid', 'serial', 'created', 'remote_addr', 'uri', 'page_title'];
    $field_columns = array_keys($all_field_keys);
    sort($field_columns);
    $header = array_merge($meta_columns, $field_columns);

    // Write CSV to a memory stream.
    $handle = fopen('php://temp', 'r+');
    if ($handle === FALSE) {
      return '';
    }

    // Write BOM for Excel UTF-8 compatibility.
    fwrite($handle, "\xEF\xBB\xBF");

    // Write header row.
    fputcsv($handle, $header);

    // Write data rows.
    foreach ($rows as $row) {
      $csv_row = [];
      foreach ($meta_columns as $col) {
        $csv_row[] = $row[$col] ?? '';
      }
      foreach ($field_columns as $col) {
        $csv_row[] = $row['data'][$col] ?? '';
      }
      fputcsv($handle, $csv_row);
    }

    // Read back the CSV content.
    rewind($handle);
    $csv_content = stream_get_contents($handle);
    fclose($handle);

    return $csv_content;
  }

  /**
   * Flatten nested data arrays into dot-notation key-value pairs.
   *
   * Webform composite fields (like name, address) store data as nested
   * arrays. This flattens them so each sub-field gets its own CSV column.
   *
   * Example: ['name' => ['first' => 'Jane', 'last' => 'Doe']]
   * Becomes: ['name.first' => 'Jane', 'name.last' => 'Doe']
   *
   * @param array $data
   *   The submission data array.
   * @param string $prefix
   *   Key prefix for recursion.
   *
   * @return array
   *   Flattened key-value pairs.
   */
  protected function flattenData(array $data, string $prefix = ''): array {
    $result = [];

    foreach ($data as $key => $value) {
      $full_key = $prefix ? "{$prefix}.{$key}" : $key;

      if (is_array($value)) {
        // Check if it's a simple indexed array (e.g., multi-select checkboxes).
        if (array_keys($value) === range(0, count($value) - 1)) {
          // Join indexed arrays as comma-separated values.
          $result[$full_key] = implode(', ', array_map('strval', $value));
        }
        else {
          // Recurse into associative arrays (composite fields).
          $result = array_merge($result, $this->flattenData($value, $full_key));
        }
      }
      else {
        $result[$full_key] = (string) $value;
      }
    }

    return $result;
  }

  /**
   * Send an email with a CSV attachment.
   *
   * Uses the standard Drupal attachment format that is compatible with:
   * - SMTP module (drupal/smtp) — uses PHPMailer
   * - SendGrid module (drupal/sendgrid_integration)
   * - Symfony Mailer module (drupal/symfony_mailer)
   * - Any mail system supporting $message['params']['attachments']
   *
   * @param string $to
   *   Recipient email address(es), comma-separated.
   * @param string $subject
   *   Email subject.
   * @param string $body
   *   Email body text.
   * @param string $filepath
   *   Full path to the CSV temp file.
   * @param string $filename
   *   The CSV filename for the attachment.
   * @param string $filecontent
   *   The raw CSV content as a string.
   *
   * @return bool
   *   TRUE if the email was accepted for delivery.
   */
  protected function sendEmail(string $to, string $subject, string $body, string $filepath, string $filename, string $filecontent): bool {
    // Build attachment in a format supported by SMTP, SendGrid, and
    // Symfony Mailer modules. We include both filepath and filecontent
    // for maximum compatibility across mail system plugins.
    $params = [
      'subject' => $subject,
      'body' => $body,
      'attachments' => [
        [
          // Used by SMTP module (PHPMailer).
          'filecontent' => $filecontent,
          'filename' => $filename,
          'filemime' => 'text/csv',
          // Also used by some mail modules that prefer file paths.
          'filepath' => $filepath,
          'mime' => 'text/csv',
        ],
      ],
    ];

    // Get the site email as the sender.
    $site_mail = \Drupal::config('system.site')->get('mail');

    $result = $this->mailManager->mail(
      'jibc_webform_export_purge',
      'weekly_export',
      $to,
      'en',
      $params,
      $site_mail,
      TRUE
    );

    if (empty($result['result'])) {
      $this->logger->error('Mail system returned failure for email to @to.', ['@to' => $to]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Delete the given submission entities.
   *
   * @param array $submissions
   *   Array of WebformSubmission entities to delete.
   */
  protected function purgeSubmissions(array $submissions): void {
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $storage->delete($submissions);
  }

  /**
   * Resolve a submission URI to a node page title.
   *
   * Strips query strings, resolves path aliases to node IDs, then loads
   * the node title. Returns an empty string for non-node pages (e.g.
   * /contact-us views pages, /registration, etc.).
   *
   * @param string $uri
   *   The raw URI stored on the submission (e.g. /course/basic-security-training).
   *
   * @return string
   *   The node title, or empty string if not resolvable.
   */
  protected function getSourcePageTitle(string $uri): string {
    if (empty($uri)) {
      return '';
    }

    // Strip query string parameters (UTM params, fbclid, etc.).
    $path = strtok($uri, '?');

    // Resolve alias to internal system path (e.g. /node/123).
    $system_path = $this->aliasManager->getPathByAlias($path);

    if (preg_match('#^/node/(\d+)$#', $system_path, $matches)) {
      $node = $this->entityTypeManager->getStorage('node')->load($matches[1]);
      if ($node) {
        return $node->getTitle();
      }
    }

    return '';
  }

}