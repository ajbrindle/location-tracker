<?php
require_once 'config.php';

// The file you uploaded (update this if you rename it to .gpx)
$filename = 'Afternoon_Ride (7).gpx';

if (!file_exists($filename)) {
    die("Error: File '$filename' not found.");
}

// Load the GPX (XML) file
$gpx = simplexml_load_file($filename);
if (!$gpx) {
    die("Error: Cannot parse the GPX file. Ensure it is valid XML.");
}

// Generate a mock workout ID
$workoutId = 'MOCK_RIDE_' . strtoupper(substr(md5(time()), 0, 8));

// Extract tracks and segments
$points = [];
if (isset($gpx->trk->trkseg->trkpt)) {
    foreach ($gpx->trk->trkseg->trkpt as $pt) {
        $lat = (string)$pt['lat'];
        $lon = (string)$pt['lon'];
        $time = (string)$pt->time;
        
        if ($lat && $lon && $time) {
            $points[] = [
                'lat' => $lat,
                'lon' => $lon,
                'time' => date('Y-m-d H:i:s', strtotime($time))
            ];
        }
    }
}

if (empty($points)) {
    die("Error: No valid track points found in the file.");
}

// The start time is the time of the very first point
$startTime = $points[0]['time'];

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

    $pdo->beginTransaction();

    // 1. Insert the main Workout record (marked as finished so the map calculates duration)
    $stmt = $pdo->prepare("INSERT INTO WORKOUTS (workout_id, start_time, finished_ind) VALUES (?, ?, 1)");
    $stmt->execute([$workoutId, $startTime]);

    // 2. Prepare the point insertion statement
    $insert_stmt = $pdo->prepare("INSERT INTO WORKOUT_POINTS (workout_id, point_id, point_time, lat, lon) VALUES (?, ?, ?, ?, ?)");

    // 3. Loop through and insert all points
    $point_id = 1;
    foreach ($points as $p) {
        $insert_stmt->execute([
            $workoutId,
            $point_id,
            $p['time'],
            $p['lat'],
            $p['lon']
        ]);
        $point_id++;
    }

    $pdo->commit();
    
    echo "<h1>Success!</h1>";
    echo "<p>Imported Workout ID: <strong>$workoutId</strong></p>";
    echo "<p>Total GPS Points Inserted: <strong>" . ($point_id - 1) . "</strong></p>";
    echo "<p><a href='index.php'>Go to your Map Dashboard</a> to see the route!</p>";

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Database Error: " . $e->getMessage());
}
?>