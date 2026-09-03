<script setup lang="ts">
import ToastStack from '@/components/common/ToastStack.vue'
import DisclaimerBar from './DisclaimerBar.vue'
import Sidebar from './Sidebar.vue'
import Topbar from './Topbar.vue'
</script>

<template>
  <div class="flex min-h-svh bg-canvas">
    <Sidebar />
    <div class="flex min-w-0 flex-1 flex-col">
      <DisclaimerBar />
      <Topbar />
      <main class="flex-1 px-3 py-4 sm:px-5 sm:py-5 lg:px-8 lg:py-6">
        <div class="mx-auto w-full max-w-[1600px]">
          <slot />
        </div>
      </main>
    </div>
    <ToastStack />
  </div>
</template>
