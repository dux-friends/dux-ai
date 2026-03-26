<script setup lang="ts">
import ChatPage from './chatPage.vue'

const props = defineProps<{
  code?: string
  sessionId?: number | null
}>()
</script>

<template>
  <div class="h-[78vh] min-h-[680px] max-h-[880px] overflow-hidden">
    <ChatPage :code="props.code" :session-id="props.sessionId" embedded />
  </div>
</template>
