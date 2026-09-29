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

    public function verify($userId, $idPath, $selfiePath)
    {
        // Verify files exist before calling Python
        if (!file_exists($idPath) || !file_exists($selfiePath)) {
            return [
                "status" => "error",
                "message" => "Image files not found on server"
            ];
        }

        // Trigger the Python process with absolute paths
        $result = PythonBridge::run('face_verify.py', [$idPath, $selfiePath]);

        if (!$result || isset($result['error'])) {
            return ["status" => "error", "message" => $result['error'] ?? "AI Execution Failed"];
        }

        $tamperFlagged = !empty($result['tamper_flagged']);
        $elaVariance = (float)($result['ela_variance'] ?? 0);
        $tamperScore = (float)($result['tamper_score'] ?? 0);
        $confidence = $tamperFlagged ? null : (float)$result['confidence'];
        $risk = "low";

        if ($tamperFlagged) {
            $status = "pending_review";
            $risk = "high";
            $this->fraudModel->logIdentityRisk(
                $userId,
                "ID image flagged by ELA: variance {$elaVariance}, tamper score {$tamperScore}"
            );
        } elseif ($confidence >= 80) {
            $status = "verified";
        } elseif ($confidence >= 45) {
            $status = "pending_review";
            $risk = "medium";
        } else {
            $status = "pending_review";
            $risk = "high";
            $this->fraudModel->logIdentityRisk($userId, "Face mismatch: {$confidence}% confidence");
        }

        // Extract just the filenames for database storage
        $idFilename = basename($idPath);
        $selfieFilename = basename($selfiePath);

        // Update Database via Model with filenames only
        $this->agentModel->updateVerificationStatus(
            $userId,
            $idFilename,
            $selfieFilename,
            $confidence,
            $status,
            $risk,
            $elaVariance,
            $tamperScore,
            $tamperFlagged
        );
        (new TrustScoreService())->recalculateAgentProperties($userId);

        return [
            "status" => "success",
            "confidence" => $confidence,
            "verification_status" => $status,
            "risk_level" => $risk,
            "ela_variance" => $elaVariance,
            "tamper_score" => $tamperScore,
            "tamper_flagged" => $tamperFlagged
        ];
    }
}
