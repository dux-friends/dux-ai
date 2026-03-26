<script setup lang="ts">
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTab, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NButton, NInput, NTabPane, NTag } from 'naive-ui'
import { computed, ref } from 'vue'
import { createKVItem, resolveSettingField } from '../../Lib/resolveSettingField.js'
import { applyToolkitMeta, buildToolkitPayload, createToolkitItem, toPlain } from '../toolHelpers.js'

const props = defineProps<{
  toolkit?: any
  toolkitRegistry: Record<string, any>
  onClose: () => void
  onConfirm: (val: any) => void
}>()

const draft = ref<any>(toPlain(props.toolkit || createToolkitItem()))

if (!draft.value.config || typeof draft.value.config !== 'object' || Array.isArray(draft.value.config)) {
  draft.value.config = {}
}
if (!draft.value.overrides || typeof draft.value.overrides !== 'object' || Array.isArray(draft.value.overrides)) {
  draft.value.overrides = {}
}

const toolkit = computed(() => draft.value)
const sharedSettings = computed(() => {
  const fields = Array.isArray(toolkit.value?._settings) ? toolkit.value._settings : []
  return fields.filter((field: any) => field && field.component !== 'field-config' && field.component !== 'note')
})
const toolkitItems = computed(() => {
  return Array.isArray(toolkit.value?._items) ? toolkit.value._items : []
})

function handleToolkitSelect(code: string | number | null) {
  applyToolkitMeta(toolkit.value, props.toolkitRegistry, code)
}

function getOverrideFields(item: any) {
  const fields = Array.isArray(item?.settings) ? item.settings : []
  return fields.filter((field: any) => field && field.component !== 'field-config' && field.component !== 'note')
}

function ensureOverrideTarget(code: string) {
  if (!toolkit.value.overrides || typeof toolkit.value.overrides !== 'object' || Array.isArray(toolkit.value.overrides)) {
    toolkit.value.overrides = {}
  }
  if (!toolkit.value.overrides[code] || typeof toolkit.value.overrides[code] !== 'object' || Array.isArray(toolkit.value.overrides[code])) {
    toolkit.value.overrides[code] = {}
  }
  return toolkit.value.overrides[code]
}

function clearOverride(code: string) {
  if (!toolkit.value.overrides || typeof toolkit.value.overrides !== 'object') {
    return
  }
  delete toolkit.value.overrides[code]
}

function handleClose() {
  props.onClose()
}

function handleConfirm() {
  props.onConfirm(buildToolkitPayload(draft.value))
}
</script>

<template>
  <DuxDrawerTab default-tab="base" :on-close="handleClose">
    <NTabPane name="base" tab="基础信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="系统工具包" required>
          <DuxSelect
            v-model:value="toolkit.toolkit"
            path="ai/agent/toolkit"
            label-field="label"
            value-field="code"
            desc-field="description"
            placeholder="选择已注册的工具包"
            @update:value="handleToolkitSelect"
          />
        </DuxFormItem>

        <DuxFormItem label="工具包名称">
          <NInput v-model:value="toolkit.label" placeholder="工具包中文名（用于 UI 展示）" />
        </DuxFormItem>

        <DuxFormItem label="工具包说明">
          <NInput v-model:value="toolkit.description" type="textarea" :rows="2" placeholder="工具包说明（可覆盖默认）" />
        </DuxFormItem>

        <DuxFormItem label="包含能力">
          <div class="flex flex-wrap gap-2">
            <NTag v-for="item in toolkitItems" :key="item.code || item.label" type="default" round>
              {{ item.label || item.code }}
            </NTag>
            <span v-if="!toolkitItems.length" class="text-xs text-muted">当前工具包暂无可预览能力</span>
          </div>
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="config" tab="共享配置">
      <DuxFormLayout label-placement="top">
        <template v-for="field in sharedSettings" :key="field.name">
          <DuxFormItem :label="field.label" :required="!!field.required" :description="field.description">
            <component
              :is="resolveSettingField(field, { createKVItem }).component"
              v-model:value="toolkit.config[field.name]"
              v-bind="resolveSettingField(field, { createKVItem }).props"
            >
              <template v-if="field.component === 'kv-input'" #default="{ value: kv }">
                <div class="flex gap-2 w-full">
                  <NInput v-model:value="kv.name" class="flex-1" :placeholder="field.componentProps?.namePlaceholder || 'Key'" />
                  <NInput v-model:value="kv.value" class="flex-1" :placeholder="field.componentProps?.valuePlaceholder || 'Value'" />
                </div>
              </template>
            </component>
          </DuxFormItem>
        </template>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="override" tab="单项覆盖">
      <div class="space-y-4">
        <div
          v-for="item in toolkitItems"
          :key="item.code"
          class="border border-muted rounded-lg p-4"
        >
          <div class="flex items-start justify-between gap-4 mb-3">
            <div>
              <div class="text-sm font-medium">{{ item.label || item.code }}</div>
              <div class="text-xs text-muted mt-1">{{ item.description || '未设置说明' }}</div>
            </div>
            <NButton quaternary size="small" @click="clearOverride(item.code)">
              清空覆盖
            </NButton>
          </div>

          <DuxFormLayout label-placement="top">
            <template v-for="field in getOverrideFields(item)" :key="`${item.code}-${field.name}`">
              <DuxFormItem :label="field.label" :required="!!field.required" :description="field.description">
                <component
                  :is="resolveSettingField(field, { createKVItem }).component"
                  v-model:value="ensureOverrideTarget(item.code)[field.name]"
                  v-bind="resolveSettingField(field, { createKVItem }).props"
                >
                  <template v-if="field.component === 'kv-input'" #default="{ value: kv }">
                    <div class="flex gap-2 w-full">
                      <NInput v-model:value="kv.name" class="flex-1" :placeholder="field.componentProps?.namePlaceholder || 'Key'" />
                      <NInput v-model:value="kv.value" class="flex-1" :placeholder="field.componentProps?.valuePlaceholder || 'Value'" />
                    </div>
                  </template>
                </component>
              </DuxFormItem>
            </template>
            <div v-if="!getOverrideFields(item).length" class="text-xs text-muted">
              当前能力没有可单独覆盖的固定配置项。
            </div>
          </DuxFormLayout>
        </div>

        <div v-if="!toolkitItems.length" class="text-sm text-muted">
          请先选择工具包。
        </div>
      </div>
    </NTabPane>

    <template #footer>
      <NButton @click="handleClose">
        取消
      </NButton>
      <NButton type="primary" @click="handleConfirm">
        保存
      </NButton>
    </template>
  </DuxDrawerTab>
</template>
