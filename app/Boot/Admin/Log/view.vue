<script setup lang="ts">
import { useOne } from '@duxweb/dvha-core'
import { DuxCodeEditor, DuxDrawerPage } from '@duxweb/dvha-pro'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const { data: info } = useOne({
  path: 'boot/log',
  id: props.id,
})
</script>

<template>
  <DuxDrawerPage :scrollbar="false">
    <div class="space-y-2">
      <div><strong>平台：</strong>{{ info?.data?.platform }}</div>
      <div><strong>方向：</strong>{{ info?.data?.direction }}</div>
      <div><strong>状态：</strong>{{ info?.data?.status }}</div>
      <div><strong>内容：</strong>{{ info?.data?.content || '-' }}</div>
      <div><strong>错误：</strong>{{ info?.data?.error || '-' }}</div>
      <div class="pt-2">
        <div class="py-2 px-4 bg-muted border border-muted border-b-none">
          原始数据
        </div>
        <DuxCodeEditor readonly :value="JSON.stringify(info?.data?.raw_payload || {}, null, '\t')" />
      </div>
    </div>
  </DuxDrawerPage>
</template>
