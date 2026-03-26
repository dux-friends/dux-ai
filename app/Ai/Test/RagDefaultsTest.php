<?php

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Models\RagKnowledge;
use App\Ai\Models\RegProvider;
use App\Ai\Service\Rag;
use App\Ai\Service\RagEngine\Service as RagEngineService;
use App\Ai\Support\AiRuntime;
use App\System\Service\Config as SystemConfig;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

it('知识库：未绑定引擎时可回退到默认知识库引擎完成同步', function () {
    $config = RegProvider::query()->create([
        'name' => 'Default Knowledge Engine',
        'code' => 'default_knowledge_engine',
        'provider' => 'neuron',
        'storage_id' => null,
        'vector_id' => 0,
        'embedding_model_id' => null,
        'config' => [],
    ]);

    SystemConfig::setValue('ai', [
            'default_rag_provider_id' => (int)$config->id,
    ]);

    $knowledge = RagKnowledge::query()->create([
        'config_id' => null,
        'name' => 'knowledge-default',
        'base_id' => null,
        'is_async' => false,
        'status' => true,
        'settings' => [],
    ]);

    Rag::syncKnowledge($knowledge->fresh());

    $updated = $knowledge->fresh();
    expect($updated->base_id)->toBe('neuron:' . $updated->id)
        ->and((bool)$updated->is_async)->toBeTrue();
});

it('知识库引擎：未选择 Embeddings 模型时回退默认 Embeddings 模型', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'rag_default_bigmodel',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Default Embedding',
        'code' => 'rag_default_embedding',
        'model' => 'embedding-3',
        'type' => AiModel::TYPE_EMBEDDING,
        'options' => [],
        'active' => true,
    ]);

    SystemConfig::setValue('ai', [
            'default_embedding_model_id' => (int)$model->id,
    ]);

    $config = RegProvider::query()->create([
        'name' => 'Knowledge Engine',
        'code' => 'knowledge_engine_without_embedding',
        'provider' => 'neuron',
        'storage_id' => null,
        'vector_id' => 1,
        'embedding_model_id' => null,
        'config' => [],
    ]);

    $service = new RagEngineService(AiRuntime::instance());
    $method = new ReflectionMethod($service, 'embeddingsProvider');
    $embedder = $method->invoke($service, $config);

    expect($embedder)->toBeInstanceOf(OpenAILikeEmbeddings::class);
});
