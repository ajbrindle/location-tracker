<?php
require_once 'config.php';

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data || !isset($data['event']) || !isset($data['workoutId'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid payload"]);
    exit;
}

$event = $data['event'];
$workoutId = $data['workoutId'];

// JS sends milliseconds. Convert to seconds.
$time_seconds = isset($data['time']) ? intval($data['time'] / 1000) : time(); 

// Create an explicit string in PHP to bypass MySQL's internal UNIXTIME conversion
$formatted_time = date('Y-m-d H:i:s', $time_seconds);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PWD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    switch ($event) {
        
        case 'workout_started':
            // Pass the explicitly formatted string directly to the database
            $stmt = $pdo->prepare("INSERT IGNORE INTO WORKOUTS (workout_id, start_time, finished_ind) VALUES (?, ?, 0)");
            $stmt->execute([$workoutId, $formatted_time]);
            break;

        case 'workout_paused':
            $stmt = $pdo->prepare("UPDATE WORKOUTS SET finished_ind = 2 WHERE workout_id = ?");
            $stmt->execute([$workoutId]);
            break;

        case 'workout_resumed':
            $stmt = $pdo->prepare("UPDATE WORKOUTS SET finished_ind = 0 WHERE workout_id = ?");
            $stmt->execute([$workoutId]);
            break;

        case 'location_update':
            if (!isset($data['lat']) || !isset($data['lon'])) {
                throw new Exception("Missing coordinates");
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(point_id), 0) + 1 FROM WORKOUT_POINTS WHERE workout_id = ?");
            $stmt->execute([$workoutId]);
            $next_point_id = $stmt->fetchColumn();

            // Applying the exact same time string formatting here
            $insert_stmt = $pdo->prepare("INSERT INTO WORKOUT_POINTS (workout_id, point_id, point_time, lat, lon) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->execute([
                $workoutId, 
                $next_point_id, 
                $formatted_time, 
                $data['lat'], 
                $data['lon']
            ]);
            
            $pdo->commit();
            break;

        case 'workout_ended':
            $stmt = $pdo->prepare("UPDATE WORKOUTS SET finished_ind = 1 WHERE workout_id = ?");
            $stmt->execute([$workoutId]);
            break;

        default:
            break;
    }

    http_response_code(200);
    echo json_encode(["success" => true, "processed_event" => $event]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database failure"]);
} catch (Exception $e) {
    error_log("Application error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>