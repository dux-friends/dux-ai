<script setup lang="ts">
import { DuxDrawerForm, DuxFormItem } from '@duxweb/dvha-pro'
import { NButton, NInput, NInputNumber, NSelect, NSwitch, useMessage } from 'naive-ui'
import { computed, ref } from 'vue'

const props = defineProps({
  id: { type: [String, Number], required: false },
})

const model = ref<Record<string, any>>({
  platform: 'dingtalk',
  enabled: true,
  config: {
    app_key: '',
    app_id: '',
    app_secret: '',
    corp_id: '',
    agent_id: undefined,
    token: '',
    aes_key: '',
    webhook: '',
    sign_secret: '',
    verification_token: '',
    encrypt_key: '',
  },
})
const message = useMessage()

const isDingtalk = computed(() => model.value.platform === 'dingtalk')
const isFeishu = computed(() => model.value.platform === 'feishu')
const isQQBot = computed(() => model.value.platform === 'qq_bot')
const isWecom = computed(() => model.value.platform === 'wecom')
const callbackBase = computed(() => {
  const base = String(model.value.callback_base || '').trim()
  if (base) {
    return base.replace(/\/+$/, '')
  }
  return (typeof window !== 'undefined' ? window.location.origin : '').replace(/\/+$/, '')
})
const callbackUrl = computed(() => {
  const code = String(model.value.code || '').trim()
  return `${callbackBase.value}/boot/webhook/${code || 'ai_customer_bot'}`
})

function copyCallbackUrl() {
  const text = callbackUrl.value
  if (!text) {
    message.error('回调地址为空')
    return
  }
  if (typeof navigator === 'undefined' || !navigator.clipboard) {
    message.error('当前环境不支持自动复制')
    return
  }
  navigator.clipboard.writeText(text)
    .then(() => {
      message.success('回调地址已复制')
    })
    .catch(() => {
      message.error('复制失败，请手动复制')
    })
}
</script>

<template>
  <DuxDrawerForm :id="props.id" path="boot/bot" :data="model" label-placement="top">
    <DuxFormItem label="实例名称">
      <NInput v-model:value="model.name" />
    </DuxFormItem>
    <DuxFormItem label="实例编码">
      <NInput v-model:value="model.code" placeholder="例如 ai_customer_bot" />
    </DuxFormItem>
    <DuxFormItem label="平台">
      <NSelect
        v-model:value="model.platform"
        :options="[
          { label: '钉钉', value: 'dingtalk' },
          { label: '飞书', value: 'feishu' },
          { label: 'QQ机器人', value: 'qq_bot' },
          { label: '企业微信', value: 'wecom' },
        ]"
      />
    </DuxFormItem>

    <DuxFormItem label="接收回调地址（自动）" description="配置到平台事件订阅 URL">
      <div class="flex gap-2">
        <NInput class="flex-1" :value="callbackUrl" readonly />
        <NButton type="primary" quaternary @click="copyCallbackUrl">
          复制
        </NButton>
      </div>
    </DuxFormItem>

    <template v-if="isDingtalk">
      <DuxFormItem label="AppKey">
        <NInput v-model:value="model.config.app_key" />
      </DuxFormItem>
      <DuxFormItem label="AppSecret">
        <NInput v-model:value="model.config.app_secret" type="password" show-password-on="mousedown" />
      </DuxFormItem>

      <DuxFormItem label="消息 Webhook" description="用于钉钉消息发送，需填写完整地址并包含 access_token">
        <NInput v-model:value="model.config.webhook" />
      </DuxFormItem>

      <DuxFormItem label="Webhook 加签密钥（可选）" description="对应钉钉自定义机器人签名 secret，填写后自动附带 timestamp/sign">
        <NInput v-model:value="model.config.sign_secret" type="password" show-password-on="mousedown" />
      </DuxFormItem>
    </template>

    <template v-if="isFeishu">
      <DuxFormItem label="App ID">
        <NInput v-model:value="model.config.app_id" />
      </DuxFormItem>
      <DuxFormItem label="App Secret">
        <NInput v-model:value="model.config.app_secret" type="password" show-password-on="mousedown" />
      </DuxFormItem>
      <DuxFormItem label="Verification Token（可选）" description="飞书事件订阅 token，用于回调校验">
        <NInput v-model:value="model.config.verification_token" />
      </DuxFormItem>
      <DuxFormItem label="Encrypt Key（可选）" description="飞书事件订阅加密 Key，用于签名与解密事件">
        <NInput v-model:value="model.config.encrypt_key" type="password" show-password-on="mousedown" />
      </DuxFormItem>
    </template>

    <DuxFormItem v-if="isQQBot" label="AppId">
      <NInput v-model:value="model.config.app_id" />
    </DuxFormItem>
    <DuxFormItem v-if="isQQBot" label="AppSecret">
      <NInput v-model:value="model.config.app_secret" type="password" show-password-on="mousedown" />
    </DuxFormItem>
    <DuxFormItem v-if="isQQBot" label="回调 Token">
      <NInput v-model:value="model.config.token" />
    </DuxFormItem>
    <DuxFormItem v-if="isWecom" label="回调 Token" description="企业微信回调配置里的 Token">
      <NInput v-model:value="model.config.token" />
    </DuxFormItem>
    <DuxFormItem v-if="isWecom" label="EncodingAESKey" description="企业微信回调配置里的 EncodingAESKey">
      <NInput v-model:value="model.config.aes_key" />
    </DuxFormItem>
    <DuxFormItem v-if="isWecom" label="企业ID（CorpID）">
      <NInput v-model:value="model.config.corp_id" />
    </DuxFormItem>
    <DuxFormItem v-if="isWecom" label="应用 Secret（企业微信）">
      <NInput v-model:value="model.config.app_secret" type="password" show-password-on="mousedown" />
    </DuxFormItem>
    <DuxFormItem v-if="isWecom" label="应用 AgentId（企业微信）">
      <NInputNumber v-model:value="model.config.agent_id" :min="1" />
    </DuxFormItem>
    
    <DuxFormItem label="备注">
      <NInput v-model:value="model.remark" type="textarea" />
    </DuxFormItem>
    <DuxFormItem label="启用">
      <NSwitch v-model:value="model.enabled" />
    </DuxFormItem>
  </DuxDrawerForm>
</template>
