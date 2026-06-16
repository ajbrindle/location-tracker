<?php
require_once 'config.php';

$workout = null;
$points = [];
$all_workouts = [];
$start_str = "";
$status_str = "";
$duration_str = "";
$last_seen_str = ""; 

// Set up explicit timezone converters
$utc_tz = new DateTimeZone('UTC');
$london_tz = new DateTimeZone('Europe/London');

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

    // 1. Fetch ALL workouts to populate the dropdown menu
    $stmt_all = $pdo->query("SELECT workout_id, start_time FROM WORKOUTS ORDER BY start_time DESC");
    $all_workouts = $stmt_all->fetchAll();

    // 2. Determine which workout to show
    $selected_id = isset($_GET['workout_id']) ? $_GET['workout_id'] : null;

    if ($selected_id) {
        $stmt = $pdo->prepare("SELECT workout_id, start_time, finished_ind FROM WORKOUTS WHERE workout_id = ?");
        $stmt->execute([$selected_id]);
        $workout = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT workout_id, start_time, finished_ind FROM WORKOUTS ORDER BY start_time DESC LIMIT 1");
        $workout = $stmt->fetch();
    }

    if ($workout) {
        // Parse Start Time as UTC, then convert to London time
        $start_time = new DateTime($workout['start_time'], $utc_tz);
        $start_time->setTimezone($london_tz);
        $start_str = $start_time->format('l, jS F Y \a\t H:i');

        // 3. Fetch all GPS points for this specific ride
        $stmt = $pdo->prepare("SELECT lat, lon, point_time FROM WORKOUT_POINTS WHERE workout_id = ? ORDER BY point_id ASC");
        $stmt->execute([$workout['workout_id']]);
        $points = $stmt->fetchAll();

        // 4. Calculate statuses based on finished_ind
        if ($workout['finished_ind'] == 1 && count($points) > 0) {
            // FINISHED
            $last_point = end($points);
            
            // Parse End Time as UTC, then convert
            $end_time = new DateTime($last_point['point_time'], $utc_tz);
            $end_time->setTimezone($london_tz);
            
            $status_str = "FINISHED at " . $end_time->format('H:i');
            
            // diff() works perfectly when both objects are explicitly defined
            $interval = $start_time->diff($end_time);
            $hours = $interval->h + ($interval->days * 24);
            $mins = $interval->i;
            
            if ($hours > 0) {
                $duration_str = "Duration: {$hours}h {$mins}m";
            } else {
                $duration_str = "Duration: {$mins}m";
            }
            
        } elseif ($workout['finished_ind'] == 2) {
            // PAUSED
            $status_str = "PAUSED";
            
            if (count($points) > 0) {
                $last_point = end($points);
                
                // Parse Paused Time as UTC, then convert
                $last_time = new DateTime($last_point['point_time'], $utc_tz);
                $last_time->setTimezone($london_tz);
                
                $last_seen_str = "Stopped since " . $last_time->format('H:i');
            } else {
                $last_seen_str = "Paused before acquiring GPS lock";
            }
            
        } elseif ($workout['finished_ind'] == 0) {
            // IN PROGRESS
            $status_str = "IN PROGRESS";
            
            if (count($points) > 0) {
                $last_point = end($points);
                
                // Parse Last Point as UTC, then convert
                $last_time = new DateTime($last_point['point_time'], $utc_tz);
                $last_time->setTimezone($london_tz);
                
                // Get the server's exact CURRENT time in London timezone
                $now = new DateTime('now', $london_tz);
                $diff = $now->diff($last_time);
                
                $time_parts = [];
                if ($diff->h > 0 || $diff->days > 0) {
                    $total_hours = $diff->h + ($diff->days * 24);
                    $time_parts[] = $total_hours . " hours";
                }
                if ($diff->i > 0) $time_parts[] = $diff->i . " minutes";
                if ($diff->s > 0) $time_parts[] = $diff->s . " seconds";
                
                if (empty($time_parts)) {
                    $last_seen_str = "Last seen just now";
                } else {
                    $last_seen_str = "Last seen " . implode(', ', $time_parts) . " ago";
                }
            } else {
                $last_seen_str = "Waiting for first GPS point...";
            }
        }
    }

} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ride Tracker Dashboard</title>

    <?php 
    // Auto-refresh every 30 seconds if the ride is NOT finished 
    // (This covers both 0: In Progress and 2: Paused)
    if ($workout && $workout['finished_ind'] != 1): 
    ?>
        <meta http-equiv="refresh" content="30">
    <?php endif; ?>
    <link rel="manifest" href="manifest.json">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f9;
            color: #333;
        }
        .header {
            padding: 20px;
            text-align: center;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 1.5rem;
            color: #2c3e50;
        }
        .header p {
            margin: 5px 0;
            font-size: 1.1rem;
            color: #555;
        }

        .header p.status-finished { color: #e74c3c; font-weight: bold; }
        .header p.status-active { color: #27ae60; font-weight: bold; }
        .header p.status-paused { color: #f39c12; font-weight: bold; }
        .last-seen { font-size: 0.95rem; color: #7f8c8d; font-style: italic; }
        
        .selector-form {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .selector-form .controls {
            display: flex;
            gap: 10px;
            align-items: center;
            max-width: 100%;
        }
        .selector-form .controls select {
            flex: 1;
        }
        .selector-form .show-latest-button {
            display: block;
            width: 100%;
            margin-top: 12px;
            background-color: #f39c12;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }
        .selector-form .show-latest-button:hover {
            background-color: #d78a0f;
        }
        select {
            padding: 8px 12px;
            font-size: 1rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            background-color: #fafafa;
            margin-right: 10px;
            max-width: 100%;
        }
        button {
            padding: 8px 16px;
            font-size: 1rem;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover { background-color: #2980b9; }

        #map {
            width: 100%;
            height: 70vh; 
            background-color: #e0e0e0;
        }
        .no-data {
            text-align: center;
            padding: 50px;
            font-size: 1.2rem;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
    <?php if (!$workout && empty($all_workouts)): ?>
        <div class="no-data">No workouts found in the database.</div>
    <?php else: ?>
        <div class="header">
            <?php if ($workout): ?>
                <h1>Ride started: <?php echo htmlspecialchars($start_str); ?></h1>
                
                <?php if ($workout['finished_ind'] == 1): ?>
                    <p class="status-finished"><?php echo htmlspecialchars($status_str); ?></p>
                    <p><?php echo htmlspecialchars($duration_str); ?></p>
                <?php elseif ($workout['finished_ind'] == 2): ?>
                    <p class="status-paused"><?php echo htmlspecialchars($status_str); ?></p>
                    <p class="last-seen"><?php echo htmlspecialchars($last_seen_str); ?></p>
                <?php else: ?>
                    <p class="status-active"><?php echo htmlspecialchars($status_str); ?></p>
                    <p class="last-seen"><?php echo htmlspecialchars($last_seen_str); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            // Determine whether the currently displayed workout is the most recent one
            $show_latest = false;
            if (!empty($all_workouts) && $workout) {
                $most_recent_id = $all_workouts[0]['workout_id'];
                if ($workout['workout_id'] != $most_recent_id) {
                    $show_latest = true;
                }
            }
            ?>

            <div class="selector-form">
                <form method="GET" action="index.php">
                    <div class="controls">
                        <select name="workout_id">
                            <?php foreach ($all_workouts as $w): 
                                // Treat dropdown start times as UTC, then convert
                                $dt = new DateTime($w['start_time'], $utc_tz);
                                $dt->setTimezone($london_tz);
                                $label = $dt->format('D, j M Y - H:i');
                                
                                $selected_attr = ($workout && $w['workout_id'] === $workout['workout_id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($w['workout_id']); ?>" <?php echo $selected_attr; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">View Ride</button>
                    </div>
                    <?php if ($show_latest): ?>
                        <button type="button" class="show-latest-button" onclick="window.location.href='index.php'">Show Latest</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div id="map"></div>

        <script>
            async function initMap() {
                const rawPoints = <?php echo json_encode($points); ?>;
                
                if (rawPoints.length === 0) {
                    document.getElementById('map').innerHTML = '<div class="no-data">Waiting for GPS points...</div>';
                    return;
                }

                const routeCoords = rawPoints.map(p => ({
                    lat: parseFloat(p.lat),
                    lng: parseFloat(p.lon)
                }));

                const { Map } = await google.maps.importLibrary("maps");
                const { AdvancedMarkerElement, PinElement } = await google.maps.importLibrary("marker");

                const map = new Map(document.getElementById("map"), {
                    mapTypeId: 'terrain',
                    mapId: 'DEMO_MAP_ID' 
                });

                const ridePath = new google.maps.Polyline({
                    path: routeCoords,
                    geodesic: true,
                    strokeColor: "#FF0000",
                    strokeOpacity: 0.8,
                    strokeWeight: 4,
                });
                ridePath.setMap(map);

                const startPin = new PinElement({
                    background: '#27ae60',
                    borderColor: '#2ecc71',
                    glyphColor: '#ffffff'
                });
                
                new AdvancedMarkerElement({
                    map: map,
                    position: routeCoords[0],
                    title: 'Start',
                    content: startPin 
                });

                const bikeWrapper = document.createElement('div');
                bikeWrapper.innerHTML = `
                    <div style="
                        background-color: rgba(255, 255, 255, 0.85);
                        border: 2px solid #2c3e50; 
                        border-radius: 50%;
                        width: 35px; 
                        height: 35px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        box-shadow: 0px 3px 6px rgba(0,0,0,0.4); 
                    ">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" width="22" height="22">
                            <path fill="#2980b9" d="M400 160C426.5 160 448 138.5 448 112C448 85.5 426.5 64 400 64C373.5 64 352 85.5 352 112C352 138.5 373.5 160 400 160zM427.2 224L365.4 175.2C348.1 161.6 323.7 161.4 306.3 174.9L223.2 239.1C192.5 262.9 194.7 309.9 227.5 330.7L288 369.1L288 480C288 497.7 302.3 512 320 512C337.7 512 352 497.7 352 480L352 352C352 341.3 346.7 331.3 337.8 325.4L295 296.9L355.3 248.4L396 281C401.7 285.5 408.7 288 416 288L480 288C497.7 288 512 273.7 512 256C512 238.3 497.7 224 480 224L427.2 224zM144 576C205.9 576 256 525.9 256 464C256 402.1 205.9 352 144 352C82.1 352 32 402.1 32 464C32 525.9 82.1 576 144 576zM496 576C557.9 576 608 525.9 608 464C608 402.1 557.9 352 496 352C434.1 352 384 402.1 384 464C384 525.9 434.1 576 496 576z"/>
                        </svg>
                    </div>`;

                new AdvancedMarkerElement({
                    map: map,
                    position: routeCoords[routeCoords.length - 1],
                    content: bikeWrapper,
                    title: 'Current Position / End'
                });

                const bounds = new google.maps.LatLngBounds();
                routeCoords.forEach(coord => bounds.extend(coord));
                map.fitBounds(bounds);
            }
        </script>
        
        <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo GMAPS_API_KEY; ?>&callback=initMap"></script>
    <?php endif; ?>

</body>
</html>