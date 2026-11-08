<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

// รับพารามิเตอร์จาก URL
$timeFrame = isset($_GET['timeFrame']) ? $_GET['timeFrame'] : 'daily';
$metric = isset($_GET['metric']) ? $_GET['metric'] : 'posts';

// ตรวจสอบพารามิเตอร์
$validTimeFrames = ['daily', 'weekly', 'monthly', 'yearly', 'all'];
$validMetrics = ['users', 'posts', 'comments', 'reports'];

if (!in_array($timeFrame, $validTimeFrames) || !in_array($metric, $validMetrics)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid timeFrame or metric']);
    exit;
}

try {
    // กำหนดฟังก์ชันสำหรับดึงข้อมูลตามเมตริก
    function getMetricData($conn, $timeFrame, $metric) {
        $labels = [];
        $values = [];
        $sql = '';

        $dateColumn = 'date'; // Default date column
        if ($metric === 'comments') {
            $dateColumn = 'date_created';
        }

        if ($timeFrame === 'all') {
            // กรณี 'all' นับทั้งหมดโดยไม่แบ่งกลุ่ม
            switch ($metric) {
                case 'users':
                    $sql = "SELECT 'All Time' as label, COUNT(*) as value FROM users";
                    break;
                case 'posts':
                    $sql = "SELECT 'All Time' as label, COUNT(*) as value FROM post WHERE date IS NOT NULL";
                    break;
                case 'comments':
                    $sql = "SELECT 'All Time' as label, COUNT(*) as value FROM comment WHERE date_created IS NOT NULL AND status = 'visible'";
                    break;
                case 'reports':
                    $sql = "SELECT 'All Time' as label, COUNT(*) as value FROM (
                                SELECT Report_date FROM comment_report WHERE Report_date IS NOT NULL AND Report_date > '0000-00-00 00:00:00'
                                UNION ALL
                                SELECT date_OPT FROM post_report WHERE date_OPT IS NOT NULL AND date_OPT > '0000-00-00 00:00:00'
                            ) as reports";
                    break;
            }
        } else {
             // CHANGE: แยกการกำหนดคอลัมน์วันที่สำหรับ reports โดยเฉพาะ
            if ($metric === 'reports') {
                $baseQuery = "FROM (
                    SELECT Report_date as report_date FROM comment_report WHERE Report_date IS NOT NULL AND Report_date > '0000-00-00 00:00:00'
                    UNION ALL
                    SELECT date_OPT as report_date FROM post_report WHERE date_OPT IS NOT NULL AND date_OPT > '0000-00-00 00:00:00'
                ) as reports";
                $dateColumn = 'report_date';
            } else {
                $table = ($metric === 'posts' || $metric === 'users') ? 'post' : 'comment';
                $baseQuery = "FROM $table WHERE $dateColumn IS NOT NULL";
                 if ($metric === 'comments') {
                    $baseQuery .= " AND status = 'visible'";
                }
            }

            // CHANGE: แก้ไข SQL queries ให้ดึง year, month, week แยกกัน
            switch ($timeFrame) {
                case 'daily':
                    $groupBy = "DATE($dateColumn)";
                    $sql = "SELECT $groupBy as label, COUNT(*) as value $baseQuery GROUP BY $groupBy";
                    break;
                case 'weekly':
                    $sql = "SELECT YEAR($dateColumn) as year, WEEK($dateColumn, 1) as week, COUNT(*) as value $baseQuery GROUP BY year, week ORDER BY year, week";
                    break;
                case 'monthly':
                    $sql = "SELECT YEAR($dateColumn) as year, MONTH($dateColumn) as month, COUNT(*) as value $baseQuery GROUP BY year, month ORDER BY year, month";
                    break;
                case 'yearly':
                    $sql = "SELECT YEAR($dateColumn) as year, COUNT(*) as value $baseQuery GROUP BY year ORDER BY year";
                    break;
            }
        }
        
        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $label = '';
                // CHANGE: แก้ไขการสร้าง Label ให้ถูกต้องตามแต่ละ Timeframe
                if ($timeFrame === 'daily') {
                    $label = date('Y-m-d', strtotime($row['label']));
                } elseif ($timeFrame === 'weekly') {
                    // สร้างวันที่จากปีและสัปดาห์ (ISO 8601)
                    $date = new DateTime();
                    $date->setISODate($row['year'], $row['week']);
                    $label = $date->format('Y-m-d'); // ให้เป็นวันจันทร์ของสัปดาห์นั้นๆ
                } elseif ($timeFrame === 'monthly') {
                    // สร้างวันที่จากปีและเดือน
                    $label = date('Y-m', strtotime("{$row['year']}-{$row['month']}-01"));
                } elseif ($timeFrame === 'yearly') {
                    $label = $row['year'];
                } else { // All time
                    $label = $row['label'];
                }

                $labels[] = $label;
                $values[] = (int)$row['value'];
            }
        }

        return ['labels' => $labels, 'values' => $values];
    }

    $data = getMetricData($conn, $timeFrame, $metric);

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>