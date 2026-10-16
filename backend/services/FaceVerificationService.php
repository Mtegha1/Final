<?php
require_once __DIR__ . '/../core/PythonBridge.php';
require_once __DIR__ . '/../models/AgentProfile.php';
require_once __DIR__ . '/../models/FraudLog.php';
require_once __DIR__ . '/TrustScoreService.php';

class FaceVerificationService
{
    private $agentModel;
    private $fraudModel;

    public function __construct()
    {
        $this->agentModel = new AgentProfile();
        $this->fraudModel = new FraudLog();
    }

    public function verify($userId, $idPath, $selfiePath, $heatmapPath)
    {
        // Verify files exist before calling Python
        if (!file_exists($idPath) || !file_exists($selfiePath)) {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification could not find both uploaded images.',
                false,
                ['status' => 'error', 'reason_code' => 'missing_image']
            );
            return [
                "status" => "error",
                "reason" => "One or both uploaded images could not be found."
            ];
        }

        try {
            $result = PythonBridge::run('face_verify.py', [$idPath, $selfiePath, $heatmapPath]);
        } catch (Throwable $error) {
            error_log('Local identity verification bridge failed: ' . $error->getMessage());
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification could not be completed by the local service.',
                false,
                ['status' => 'error', 'reason_code' => 'verification_bridge_error']
            );
            return [
                "status" => "error",
                "reason" => "The local verification service could not run. Please retry or contact an administrator."
            ];
        }
        $allowedStatuses = ['verified', 'manual_review', 'rejected', 'error'];
        if (!isset($result['status']) || !in_array($result['status'], $allowedStatuses, true)) {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification returned an invalid result.',
                false,
                ['status' => 'error', 'reason_code' => 'invalid_result']
            );
            return ["status" => "error", "reason" => "The verification service returned an invalid result. Please retry."];
        }
        if ($result['status'] === 'error') {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification needs a retake or retry.',
                false,
                [
                    'status' => 'error',
                    'reason' => (string)($result['reason'] ?? 'Image quality or local verification error.')
                ]
            );
            return [
                "status" => "error",
                "reason" => $result['reason'] ?? "The verification service could not process these images.",
            ];
        }

        if (
            !isset($result['tamper_score']) ||
            !is_numeric($result['tamper_score']) ||
            (float)$result['tamper_score'] < 0 ||
            (float)$result['tamper_score'] > 100 ||
            !in_array($result['liveness_status'] ?? '', ['passed', 'review', 'not_checked'], true) ||
            !is_file($heatmapPath)
        ) {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification returned an incomplete result.',
                false,
                ['status' => 'error', 'reason_code' => 'incomplete_result']
            );
            return ["status" => "error", "reason" => "The verification result was incomplete. Please retry."];
        }
        if (
            isset($result['match_confidence']) &&
            (!is_numeric($result['match_confidence']) || (float)$result['match_confidence'] < 0 || (float)$result['match_confidence'] > 100)
        ) {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification returned an invalid result.',
                false,
                ['status' => 'error', 'reason_code' => 'invalid_scores']
            );
            return ["status" => "error", "reason" => "The verification result was invalid. Please retry."];
        }
        if (isset($result['distance']) && !is_numeric($result['distance'])) {
            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                'Identity verification returned an invalid result.',
                false,
                ['status' => 'error', 'reason_code' => 'invalid_distance']
            );
            return ["status" => "error", "reason" => "The verification result was invalid. Please retry."];
        }

        $decisionStatus = $result['status'];
        $status = $decisionStatus === 'manual_review' ? 'pending_review' : $decisionStatus;
        $tamperScore = (float)$result['tamper_score'];
        $confidence = isset($result['match_confidence']) ? (float)$result['match_confidence'] : null;
        $distance = isset($result['distance']) ? (float)$result['distance'] : null;
        $tamperFlagged = !empty($result['tamper_flagged']);
        $risk = $status === 'verified' ? 'low' : ($status === 'pending_review' ? 'medium' : 'high');
        $reason = (string)($result['reason'] ?? 'Verification completed.');
        $livenessStatus = (string)($result['liveness_status'] ?? 'not_checked');

        $db = Database::getInstance()->conn;
        $db->beginTransaction();
        try {
            $this->agentModel->updateVerificationStatus(
                $userId,
                basename($idPath),
                basename($selfiePath),
                $confidence,
                $status,
                $risk,
                null,
                $tamperScore,
                $tamperFlagged,
                $distance,
                basename($heatmapPath),
                $livenessStatus
            );

            $this->fraudModel->logIdentityRisk(
                (int)$userId,
                "Identity verification {$decisionStatus}: {$reason}",
                $status !== 'verified',
                [
                    'status' => $decisionStatus,
                    'tamper_score' => $tamperScore,
                    'match_confidence' => $confidence,
                    'tamper_flagged' => $tamperFlagged,
                    'liveness_status' => $livenessStatus,
                    'reason' => $reason
                ]
            );
            (new TrustScoreService())->recalculateAgentProperties($userId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            "status" => $decisionStatus,
            "verification_status" => $status,
            "match_confidence" => $confidence,
            "distance" => $distance,
            "tamper_score" => $tamperScore,
            "liveness_status" => $livenessStatus,
            "reason" => $reason,
        ];
    }
}
