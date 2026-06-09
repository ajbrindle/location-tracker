<!DOCTYPE html>
<html>
<head>
    <title>Workout Tracker Logs</title>
    <meta http-equiv="refresh" content="10">
    <style>
        body { font-family: monospace; background: #111; color: #0f0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h2 { color: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Live Tracker Logs</h2>
        <pre>
<?php
$logFile = 'tracker_log.txt';
if (file_exists($logFile)) {
    echo htmlspecialchars(file_get_contents($logFile));
} else {
    echo "Waiting for data... No logs found yet.";
}
?>
        </pre>
    </div>
</body>
</html>