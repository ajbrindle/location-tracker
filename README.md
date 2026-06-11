# Zepp OS Live Cycling Tracker

A custom GPS tracking solution bridging a Zepp OS smartwatch and a self-hosted PHP/MySQL backend. Allows location sharing during a workout, which uploads to a live-updating Google Maps dashboard on the web.

## 🏗 Architecture

The system is divided into two distinct components: a low-power telemetry client running on the wrist, and a relational database/dashboard hosted on the web.

* `/watch-app` - The Zepp OS client application.
* `/server` - The PHP REST API and web visualization dashboard.

## ✨ Core Features

* **Bulletproof Hardware UI:** The watch interface utilizes large, stacked touch-targets and relies on the watch's physical hardware interrupt to wake the screen. This prevents rogue raindrops or sweaty sleeves from accidentally pausing the ride.
* **Low-Power Heartbeat:** Relies on a highly efficient 30-second telemetry sync, keeping the watch battery draw minimal during long endurance rides.
* **Live Dashboard:** A responsive web interface utilizing the Google Maps `AdvancedMarkerElement` API, featuring custom SVG dual-color tracking pins.
* **Asynchronous State Management:** Accurately handles "In Progress", "Paused", and "Finished" states, including conditional auto-refreshing on the dashboard only when a ride is active.
* **Server-Side Timestamps:** Mitigates timezone drift by relying entirely on local PHP server configurations and parsing timestamps natively for the frontend.

---

## ⌚ Watch App Setup (`/watch-app`)

The smartwatch client is built using the Zepp OS ZML framework.

### Prerequisites
* Node.js installed on your local machine.
* Zeus CLI (`@zeppos/zeus-cli`) installed globally.
* Developer Mode enabled on your Zepp application and Amazfit/Zepp smartwatch.

### Deployment
1. Navigate to the `watch-app` directory.
2. Install dependencies:
   ```bash
   npm install
   ```
3. Update the path to receiver.php in app-side/index.js
4. Connect your watch via the Zepp app bridge and install the app using the Zeus CLI:
   ```bash
   zeus dev
   ```

---

## 🖥 Server Setup (`/server`)

The backend is a standard LAMP/LEMP stack application utilizing PDO for secure database interactions.

### 1. Database Schema
Execute the following SQL in your MySQL environment to provision the tables:

```sql
CREATE TABLE WORKOUTS (
    workout_id VARCHAR(50) PRIMARY KEY,
    start_time DATETIME NOT NULL,
    finished_ind TINYINT(1) DEFAULT 0
);

CREATE TABLE WORKOUT_POINTS (
    workout_id VARCHAR(50) NOT NULL,
    point_id INT NOT NULL,
    point_time DATETIME NOT NULL,
    lat DECIMAL(10, 8) NOT NULL,
    lon DECIMAL(11, 8) NOT NULL,
    PRIMARY KEY (workout_id, point_id),
    FOREIGN KEY (workout_id) REFERENCES WORKOUTS(workout_id) ON DELETE CASCADE
);
```

### 2. Configuration File
You must create a configuration file in the root of the `/server` directory before deployment. 

**`config.php`** (API Keys and DB credentials)
```php
<?php
define('GMAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY');
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PWD', 'your_db_password');
define('DB_NAME', 'your_db_name');
?>
```

### 3. Deployment
Upload the contents of the `/server` directory to your web host. Ensure the Zepp OS watch app is configured to point to the correct URL for `receiver.php` to accept incoming telemetry payloads. This should be exported as a constant RECEIVER_URL in a file called config.js in the app-side directory.

---

## 🚦 Usage Notes

* **Starting a Ride:** Start a workout on the watch and then swipe up/down until the tracker extension is shown on the screen. It will immediately acquire a GPS lock and send the `workout_started` payload. Remain on this screen in order for the location to continually (every 30 seconds) update. If leaving the screen, return to it to resume.
* **Pausing/Stopping:** Due to Zepp OS power management, the screen digitizer will sleep. Press the physical watch crown to wake the screen before interacting with the Pause or Stop buttons.
* **Viewing the Dashboard:** Navigate to `index.php` in any modern web browser. The dashboard defaults to the most recent ride and will auto-refresh every 30 seconds if the ride state is active.