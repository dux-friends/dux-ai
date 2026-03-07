<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import { useCustomMutation } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { DuxCardItem, DuxCardPage, useAction } from '@duxweb/dvha-pro'
import { computed, h, onMounted, ref } from 'vue'

const path = 'boot/bot'
const action = useAction()
const request = useCustomMutation()

interface PlatformOption {
  label: string
  value: string
  icon?: string
  color?: string
}

const fallbackPlatformOptions: PlatformOption[] = [
  { label: '钉钉', value: 'dingtalk' },
  { label: '飞书', value: 'feishu' },
  { label: 'QQ机器人', value: 'qq_bot' },
  { label: '企业微信', value: 'wecom' },
]

const platformOptions = ref<PlatformOption[]>([])
const platformMap = computed<Record<string, PlatformOption>>(() => {
  return platformOptions.value.reduce((acc: Record<string, PlatformOption>, item) => {
    const key = String(item.value || '').trim().toLowerCase()
    if (key) {
      acc[key] = item
    }
    return acc
  }, {})
})

const filterPlatformOptions = computed(() => {
  return platformOptions.value.length ? platformOptions.value : fallbackPlatformOptions
})

const defaultPlatform: { icon: string, color: DuxCardItemColor, label: string } = {
  icon: 'i-tabler:robot',
  color: 'primary',
  label: '',
}

function getPlatform(key: string) {
  const item = platformMap.value[String(key || '').trim().toLowerCase()]
  if (!item) {
    return defaultPlatform
  }
  return {
    icon: String(item.icon || '').trim() || defaultPlatform.icon,
    color: (String(item.color || '').trim() || defaultPlatform.color) as DuxCardItemColor,
    label: String(item.label || '').trim() || key || defaultPlatform.label,
  }
}

async function loadPlatformOptions() {
  const res = await request.mutateAsync({
    path: 'boot/bot/platforms',
    method: 'GET',
  })
  const list = Array.isArray(res.data) ? res.data : []
  platformOptions.value = list
    .map((item: any) => ({
      label: String(item?.label || ''),
      value: String(item?.value || ''),
      icon: String(item?.icon || ''),
      color: String(item?.color || ''),
    }))
    .filter((item: PlatformOption) => item.value !== '')
}

const actions: UseActionItem[] = [
  {
    label: '新增',
    color: 'primary',
    icon: 'i-tabler:plus',
    type: 'drawer',
    component: () => import('./form.vue'),
    width: 700,
  },
]

const rowActions: UseActionItem[] = [
  { label: '编辑', type: 'drawer', component: () => import('./form.vue'), width: 700 },
  { label: '删除', type: 'delete', path },
]

function handleAction(item: UseActionItem, row: Record<string, any>) {
  action.target({
    id: row.id,
    data: row,
    item,
  })
}

function getMenu(row: Record<string, any>) {
  return rowActions.map((item, index) => ({
    label: item.label || '',
    key: `${index}`,
    onClick: () => handleAction(item, row),
  }))
}

function getExtends(row: Record<string, any>): DuxCardItemExtendItem[] {
  return [
    {
      label: '编码',
      value: row.code || '-',
    },
    {
      label: '状态',
      align: 'right' as const,
      value: h('div', { class: 'flex items-center gap-1.5 mt-1' }, [
        h('span', {
          class: `inline-block size-1.5 rounded-full ${row.enabled ? 'bg-green-500 animate-pulse' : 'bg-neutral-300 dark:bg-neutral-600'}`,
        }),
        row.enabled ? '运行中' : '已停用',
      ]),
    },
  ]
}

const filter = ref<Record<string, any>>({})
const filterSchema = computed<JsonSchemaNode[]>(() => [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      'placeholder': '搜索名称/编码',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
  {
    tag: 'n-select',
    name: 'platform',
    attrs: {
      'placeholder': '平台',
      'clearable': true,
      'options': filterPlatformOptions.value.map(item => ({
        label: item.label,
        value: item.value,
      })),
      'v-model:value': [filter.value, 'platform'],
    },
  },
])

onMounted(() => {
  loadPlatformOptions().catch(() => {})
})
</script>

<template>
  <DuxCardPage
    :path="path"
    :filter="filter"
    :filter-schema="filterSchema"
    :actions="actions"
  >
    <template #default="{ item }">
      <DuxCardItem
        :title="item.name"
        :desc="item.platform_name || getPlatform(item.platform).label || item.platform || '-'"
        :icon="getPlatform(item.platform).icon"
        :color="getPlatform(item.platform).color"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      />
    </template>
  </DuxCardPage>
</template>
