<?php
/**
 * Export Controller
 *
 * Thin HTTP layer providing CSV and JSON file downloads of scan audit results.
 */

declare(strict_types=1);

namespace LoremChecker\Api;

use LoremChecker\Export\ExportService;
use PDO;

class ExportController
{
    private ExportService $exportService;

    public function __construct(PDO $db)
    {
        $this->exportService = new ExportService($db);
    }

    public function export(): void
    {
        $scanId    = isset($_GET['scan_id']) && is_numeric($_GET['scan_id']) ? (int)$_GET['scan_id'] : null;
        $projectId = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : null;
        $format    = strtolower($_GET['format'] ?? 'csv');

        try {
            $data = $this->exportService->getExportData($scanId, $projectId);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 'VALIDATION_ERROR', 400);
            return;
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 'NOT_FOUND', 404);
            return;
        }

        $safeName = $data['safe_name'];
        $scanId   = (int)$data['scan']['id'];

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $safeName . '_scan_' . $scanId . '_results.json"');
            echo $this->exportService->generateJson($data);
            exit(0);
        }

        // CSV export (default)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '_scan_' . $scanId . '_results.csv"');
        echo $this->exportService->generateCsv($data);
        exit(0);
    }
}
