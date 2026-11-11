<?php
/**
 * Scan Recovery Manager
 *
 * Recovers scans and orphaned jobs after sudden server or worker termination:
 * - Detects stale scans whose workers missed heartbeats
 * - Resets dangling 'processing' URLs back to 'pending' so they are not abandoned
 * - Ensures completed URLs are NOT re-processed
 */

namespace LoremChecker\Worker;

use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;

class ScanRecovery
{
    private ScanRepository $scanRepo;
    private UrlRepository  $urlRepo;
    private LogRepository  $logRepo;

    public function __construct(ScanRepository $scanRepo, UrlRepository $urlRepo, LogRepository $logRepo)
    {
        $this->scanRepo = $scanRepo;
        $this->urlRepo  = $urlRepo;
        $this->logRepo  = $logRepo;
    }

    /**
     * Inspect active scans and recover stale ones.
     *
     * @param int $thresholdSeconds Inactivity seconds before considering worker dead
     * @return array List of recovered scan IDs
     */
    public function recoverStaleScans(int $thresholdSeconds = 120): array
    {
        $staleScans = $this->scanRepo->getStaleScans($thresholdSeconds);
        $recoveredIds = [];

        foreach ($staleScans as $scan) {
            $scanId = (int)$scan['id'];
            $projectId = (int)$scan['project_id'];

            // 1. Reset any URLs stuck in 'processing' status back to 'pending'
            $resetCount = $this->urlRepo->resetProcessingToPending($scanId);

            // 2. Mark scan as interrupted
            $this->scanRepo->updateStatus($scanId, 'interrupted');

            // 3. Log recovery event
            $msg = sprintf(
                'Worker heartbeat timed out (last heartbeat: %s). Scan marked interrupted. %d in-flight URL(s) reset to pending.',
                $scan['last_heartbeat_at'],
                $resetCount
            );
            $this->logRepo->log($scanId, $projectId, 'WARNING', $msg, 'recovery');

            $recoveredIds[] = $scanId;
        }

        return $recoveredIds;
    }
}
