<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { DuxTablePage, useAction } from '@duxweb/dvha-pro'
import { ref } from 'vue'

const action = useAction()

const columns: TableColumn[] = [
  { title: '#', key: 'id', width: 80 },
  { title: '方向', key: 'direction', width: 100 },
  { title: '平台', key: 'platform', width: 110 },
  { title: '状态', key: 'status', width: 100 },
  { title: '发送者', key: 'sender_name', minWidth: 120 },
  { title: '会话ID', key: 'conversation_id', minWidth: 150 },
  { title: '事件ID', key: 'event_id', minWidth: 150 },
  { title: '内容', key: 'content', minWidth: 260 },
  { title: '时间', key: 'created_at', minWidth: 170 },
  {
    title: '操作',
    key: 'action',
    width: 160,
    fixed: 'right',
    render: action.renderTable({
      type: 'button',
      text: true,
      items: [
        { label: '详情', type: 'drawer', component: () => import('./view.vue') },
        { label: '删除', type: 'delete', path: 'boot/log' },
      ],
    }),
  },
]

const filter = ref<Record<string, any>>({})
const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      'placeholder': '搜索内容/事件ID/发送者',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
  {
    tag: 'n-select',
    name: 'platform',
    attrs: {
      'placeholder': '平台',
      'clearable': true,
      'options': [
        { label: '钉钉', value: 'dingtalk' },
        { label: '飞书', value: 'feishu' },
        { label: 'QQ机器人', value: 'qq_bot' },
        { label: '企业微信', value: 'wecom' },
      ],
      'v-model:value': [filter.value, 'platform'],
    },
  },
  {
    tag: 'n-select',
    name: 'direction',
    attrs: {
      'placeholder': '方向',
      'clearable': true,
      'options': [
        { label: '接收', value: 'inbound' },
        { label: '发送', value: 'outbound' },
      ],
      'v-model:value': [filter.value, 'direction'],
    },
  },
  {
    tag: 'n-select',
    name: 'status',
    attrs: {
      'placeholder': '状态',
      'clearable': true,
      'options': [
        { label: '成功', value: 'ok' },
        { label: '失败', value: 'fail' },
        { label: '忽略', value: 'ignored' },
        { label: '超时', value: 'timeout' },
      ],
      'v-model:value': [filter.value, 'status'],
    },
  },
]
</script>

<template>
  <DuxTablePage
    path="boot/log"
    :filter="filter"
    :filter-schema="filterSchema"
    :columns="columns"
  />
</template>
