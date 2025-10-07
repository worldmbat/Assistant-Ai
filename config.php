<?php
// config.php
// ---------------------------
// 将你的 DeepSeek API Key 填到下面（或在服务器环境变量里设置 DEEPSEEK_API_KEY）
// 优先读取环境变量，其次读取此处的配置项。
// ---------------------------
return [
  // 供应商（目前仅 deepseek）
  'provider' => 'deepseek',
  // 如果未设置环境变量 DEEPSEEK_API_KEY，则使用此处的 key
  'deepseek_api_key' => 'put your own DeepSeek API Key here',
  // DeepSeek 模型：deepseek-chat / deepseek-reasoner 等
  'model' => 'deepseek-chat',
  // 系统提示词：可根据需要调整
  'system_prompt' => 'You are a helpful assistant，用户用什么语言问你，你就用什么语言回答.',
  // 请求超时（秒）
  'timeout' => 30,
];
