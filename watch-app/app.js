import { BaseApp } from '@zeppos/zml/base-app'

App(
  BaseApp({
    globalData: {},
    onCreate() {
      console.log('[APP] ZML Bridge Initialized')
    },
    onDestroy() {}
  })
)