<?php
class PriceAnalysisService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function check($area_name, $propertyType, $price)
    {
        $sql = "SELECT avg_price FROM zone_market_rates 
                WHERE city = ? AND property_type = ? LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$area_name, $propertyType]);
        $data = $stmt->fetch();

        if (!$data || (float)$data['avg_price'] <= 0) {
            return ['flag' => false, 'deviation' => 0.0, 'avg_price' => null];
        }

        $avg = (float)$data['avg_price'];
        $deviation = abs((($avg - (float)$price) / $avg) * 100);
        $threshold = $avg * 0.5;

        if ($price < $threshold) {
            return [
                'flag' => true,
                'avg_price' => $avg,
                'deviation' => $deviation
            ];
        }

        return ['flag' => false, 'avg_price' => $avg, 'deviation' => $deviation];
    }
}
