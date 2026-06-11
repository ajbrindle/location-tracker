import { BaseSideService } from '@zeppos/zml/base-side'
import { RECEIVER_URL } from './config'

var isPaused = false;

AppSideService(
  BaseSideService({
    onInit() {
      this.currentWorkoutId = null;
      this.watchdogTimer = null; 
    },

    onRequest(req, res) {
      if (req.method === 'POST_LOCATION') {
        const payload = req.params;

        // Reset the cafe-stop timer every time a new ping arrives
        if (this.watchdogTimer) clearTimeout(this.watchdogTimer);
        
        if (payload.event === 'workout_started') {
          this.currentWorkoutId = payload.workoutId;
        } else if (payload.event === 'workout_paused') {
          isPaused = true;
        } else if (payload.event === 'workout_resumed') {
          isPaused = false;
        }

        fetch(RECEIVER_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload) 
        })
        .then(response => {
          // 1. Tell the phone to actually read the JSON body from the server
          return response.json() 
        })
        .then(data => {
          // 2. Forward the server's exact JSON payload back to the watch
          res(null, data)
        })
        .catch(error => res(null, { success: false, error: error.message }));

        // Start the 1-minute watchdog
        this.watchdogTimer = setTimeout(() => {
          if (this.currentWorkoutId) {
            this.forceEndWorkout(this.currentWorkoutId);
            this.currentWorkoutId = null; 
          }
        }, 300000); 
      }
    },

    forceEndWorkout(workoutId) {
      if (isPaused) return; // Don't end if currently paused
      
      fetch(RECEIVER_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          event: 'workout_ended',
          workoutId: workoutId,
          time: new Date().getTime()
        })
      }).catch(err => console.log('Final sync failed:', err));
    },

    onDestroy() {
      if (this.currentWorkoutId) {
        this.forceEndWorkout(this.currentWorkoutId);
        if (this.watchdogTimer) clearTimeout(this.watchdogTimer);
      }
    }
  })
)