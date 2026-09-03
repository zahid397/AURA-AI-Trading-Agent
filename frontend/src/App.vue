<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '@/components/layout/AppShell.vue'
import ToastStack from '@/components/common/ToastStack.vue'

const route = useRoute()
const isBareLayout = computed(() => route.meta.requiresAuth === false)
</script>

<template>
  <AppShell v-if="!isBareLayout">
    <router-view />
  </AppShell>
  <template v-else>
    <router-view />
    <ToastStack />
  </template>
</template>
