<?php
/**
 * API Router
 *
 * Routes incoming API requests to appropriate controllers.
 */

namespace LoremChecker\Api;

use PDO;

class Router
{
    private PDO   $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db     = $db;
        $this->config = $config;
    }

    public function dispatch(): void
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

        $projectCtrl = new ProjectController($this->db);
        $scanCtrl    = new ScanController($this->db, $this->config);
        $resultsCtrl = new ResultsController($this->db);
        $rulesCtrl   = new RulesController($this->db);
        $exportCtrl  = new ExportController($this->db);

        switch ($action) {
            case 'start':
                $scanCtrl->start();
                break;
            case 'status':
                $scanCtrl->status();
                break;
            case 'list':
                $projectCtrl->list();
                break;
            case 'delete':
                $projectCtrl->delete();
                break;
            case 'details':
                $projectCtrl->getDetails();
                break;
            case 'pause':
                $scanCtrl->pause();
                break;
            case 'resume':
                $scanCtrl->resume();
                break;
            case 'cancel':
                $scanCtrl->cancel();
                break;
            case 'logs':
                $scanCtrl->logs();
                break;
            case 'results':
                $resultsCtrl->pageResults();
                break;
            case 'findings':
                $resultsCtrl->findings();
                break;
            case 'dashboard_stats':
                $resultsCtrl->dashboardStats();
                break;
            case 'scan_summary':
                $resultsCtrl->scanSummary();
                break;
            case 'page_detail':
                $resultsCtrl->pageDetail();
                break;
            case 'scans_list':
                $scanCtrl->scansList();
                break;
            case 'compare':
                $resultsCtrl->compare();
                break;
            case 'export':
                $exportCtrl->export();
                break;
            case 'rules_list':
                $rulesCtrl->list();
                break;
            case 'rules_create':
                $rulesCtrl->create();
                break;
            case 'rules_update':
                $rulesCtrl->update();
                break;
            case 'rules_toggle':
                $rulesCtrl->toggle();
                break;
            case 'rules_delete':
                $rulesCtrl->delete();
                break;
            default:
                Response::error("Unknown action: '{$action}'", 'INVALID_ACTION', 400);
        }
    }
}
