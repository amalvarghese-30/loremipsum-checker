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
            case 'compare':
                $resultsCtrl->compare();
                break;
            case 'export':
                $exportCtrl->export();
                break;
            default:
                Response::error("Unknown action: '{$action}'", 'INVALID_ACTION', 400);
        }
    }
}
