<?php

namespace Drupal\jibc_webform_export_purge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\jibc_webform_export_purge\WebformExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for manually triggering an export.
 */
class ManualExportController extends ControllerBase {

  /**
   * The export service.
   *
   * @var \Drupal\jibc_webform_export_purge\WebformExportService
   */
  protected $exportService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->exportService = $container->get('jibc_webform_export_purge.export_service');
    return $instance;
  }

  /**
   * Run the export manually, regardless of schedule.
   */
  public function run() {
    $config = $this->config('jibc_webform_export_purge.settings');
    $email_to = $config->get('email_to');
    $webform_ids = $config->get('webform_ids') ?: [];

    if (empty($email_to) || empty($webform_ids)) {
      $this->messenger()->addError($this->t('Cannot run export: email address or webform IDs are not configured. Please configure them first.'));
    }
    else {
      try {
        $this->exportService->exportAndPurge($webform_ids, $email_to);
        $this->messenger()->addStatus($this->t('Export and purge completed successfully. Check <a href="@logs">the logs</a> for details.', [
          '@logs' => Url::fromRoute('dblog.overview')->toString(),
        ]));
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Export failed: @message', ['@message' => $e->getMessage()]));
        $this->getLogger('jibc_webform_export_purge')->error('Manual export failed: @message', ['@message' => $e->getMessage()]);
      }
    }

    $url = Url::fromRoute('jibc_webform_export_purge.settings')->toString();
    return new RedirectResponse($url);
  }

}
