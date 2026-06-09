import { createWidget, widget, prop, align } from '@zos/ui'
import { Geolocation } from '@zos/sensor'
import { BasePage } from '@zeppos/zml/base-page'

function formatDMS(decimalCoords, isLatitude) {
  const absolute = Math.abs(decimalCoords)
  const degrees = Math.floor(absolute)
  
  const minutesFloat = (absolute - degrees) * 60
  const minutes = Math.floor(minutesFloat)
  
  const seconds = ((minutesFloat - minutes) * 60).toFixed(1)
  
  let direction = ''
  if (isLatitude) {
    direction = decimalCoords >= 0 ? 'N' : 'S'
  } else {
    direction = decimalCoords >= 0 ? 'E' : 'W'
  }
  
  return `${degrees}° ${minutes}' ${seconds}" ${direction}`
}

DataWidget(
  BasePage({
    onInit() {
      this.workoutId = 'RIDE_' + new Date().getTime().toString(36).toUpperCase()
      this.isPaused = false;
      this.isEnded = false;
      
      this.request({
        method: 'POST_LOCATION',
        params: {
          event: 'workout_started',
          workoutId: this.workoutId,
          time: new Date().getTime()
        }
      }).catch(err => console.log('Init sync failed:', err))
    },

    build() {
      // 1. Shifted all Y-coordinates down by 40px to clear the system status bar
      this.statusText = createWidget(widget.TEXT, {
        x: 0, y: 60, w: 480, h: 45,
        color: 0x00bfff, text_size: 28, align_h: align.CENTER_H,
        text: 'TRACKER ACTIVE'
      })
      
      this.latText = createWidget(widget.TEXT, {
        x: 0, y: 115, w: 480, h: 50,
        color: 0xffffff, text_size: 38, align_h: align.CENTER_H,
        text: 'Waiting for'
      })

      this.lonText = createWidget(widget.TEXT, {
        x: 0, y: 170, w: 480, h: 50,
        color: 0xffffff, text_size: 38, align_h: align.CENTER_H,
        text: 'GPS lock...'
      })

      this.syncText = createWidget(widget.TEXT, {
        x: 0, y: 240, w: 480, h: 40,
        color: 0xaaaaaa, text_size: 24, align_h: align.CENTER_H,
        text: 'Last sync: Never'
      })

      // ----------------------------------------------------
      // BULLETPROOF BUTTON LAYOUT (100x100px, Shifted to Y: 310)
      // Swapped Unicode for clean Text labels
      // ----------------------------------------------------
      
      // PAUSE BUTTON (Visible by default)
      this.pauseButton = createWidget(widget.BUTTON, {
        x: 100, y: 310, w: 100, h: 100, radius: 50, 
        normal_color: 0x555555, press_color: 0x333333, // Grey
        text: 'PAUSE', text_size: 22, color: 0xffffff, // Replaced symbol
        click_func: () => this.handlePause()
      })

      // PLAY BUTTON (Hidden by default, stacked directly under Pause)
      this.playButton = createWidget(widget.BUTTON, {
        x: 100, y: 310, w: 100, h: 100, radius: 50, 
        normal_color: 0x27ae60, press_color: 0x2ecc71, // Green
        text: 'PLAY', text_size: 24, color: 0xffffff, // Replaced symbol
        click_func: () => this.handleResume()
      })
      this.playButton.setProperty(prop.VISIBLE, false)

      // STOP BUTTON
      this.stopButton = createWidget(widget.BUTTON, {
        x: 280, y: 310, w: 100, h: 100, radius: 50, 
        normal_color: 0xe74c3c, press_color: 0xc0392b, // Red
        text: 'STOP', text_size: 24, color: 0xffffff, // Replaced symbol
        click_func: () => this.endWorkout()
      })

      // Local memory to store the latest coordinates
      this.currentLat = 0
      this.currentLon = 0
      this.geolocation = new Geolocation()
      
      this.geolocation.onChange(() => {
        if (this.isEnded) return;

        this.currentLat = this.geolocation.getLatitude()
        this.currentLon = this.geolocation.getLongitude()

        if (this.currentLat !== 0 && this.currentLon !== 0) {
          this.latText.setProperty(prop.TEXT, { text: formatDMS(this.currentLat, true) })
          this.lonText.setProperty(prop.TEXT, { text: formatDMS(this.currentLon, false) })
        }
      })

      this.geolocation.start()

      // The Heartbeat
      this.syncTimer = setInterval(() => {
        if (this.currentLat !== 0 && this.currentLon !== 0 && !this.isPaused && !this.isEnded) {
           this.request({
             method: 'POST_LOCATION',
             params: {
               event: 'location_update',
               workoutId: this.workoutId,
               time: new Date().getTime(),
               lat: this.currentLat,
               lon: this.currentLon
             }
           }).catch(err => console.log('Sync failed:', err))
           
           const d = new Date()
           const hours = String(d.getHours()).padStart(2, '0')
           const mins = String(d.getMinutes()).padStart(2, '0')
           const secs = String(d.getSeconds()).padStart(2, '0')
           
           this.syncText.setProperty(prop.TEXT, { text: `Last sync: ${hours}:${mins}:${secs}` })
        }
      }, 30000) 
    },

    handlePause() {
      if (this.isEnded) return;
      this.isPaused = true;
      
      this.pauseButton.setProperty(prop.VISIBLE, false);
      this.playButton.setProperty(prop.VISIBLE, true);
      
      this.statusText.setProperty(prop.TEXT, { text: 'TRACKER PAUSED' });
      this.statusText.setProperty(prop.COLOR, 0xffa500); // Orange
      
      this.request({
        method: 'POST_LOCATION',
        params: { event: 'workout_paused', workoutId: this.workoutId, time: new Date().getTime() }
      }).catch(err => console.log('Pause sync failed:', err));
    },

    handleResume() {
      if (this.isEnded) return;
      this.isPaused = false;
      
      this.playButton.setProperty(prop.VISIBLE, false);
      this.pauseButton.setProperty(prop.VISIBLE, true);
      
      this.statusText.setProperty(prop.TEXT, { text: 'TRACKER ACTIVE' });
      this.statusText.setProperty(prop.COLOR, 0x00bfff); // Blue
      
      this.request({
        method: 'POST_LOCATION',
        params: { event: 'workout_resumed', workoutId: this.workoutId, time: new Date().getTime() }
      }).catch(err => console.log('Resume sync failed:', err));
    },

    endWorkout() {
      if (this.isEnded) return;

      this.isEnded = true;
      this.statusText.setProperty(prop.TEXT, { text: 'TRACKER STOPPED' });
      this.statusText.setProperty(prop.COLOR, 0xe74c3c); // Red
      
      if (this.geolocation) this.geolocation.stop()
      if (this.syncTimer) clearInterval(this.syncTimer)

      this.request({
        method: 'POST_LOCATION',
        params: { event: 'workout_ended', workoutId: this.workoutId, time: new Date().getTime() }
      }).catch(err => console.log('End sync failed:', err));
    },

    onDestroy() {
      if (this.geolocation) this.geolocation.stop()
      if (this.syncTimer) clearInterval(this.syncTimer)
    }
  })
)