const test = require("node:test");
const assert = require("node:assert/strict");
const {
  buildPrompt,
  extractJson,
  generateArticle,
} = require("../src/article-generator");
const { parseArgs, run } = require("../src/cli");
const { loadAiConfig, loadSiteConfig } = require("../src/config");
const { buildWordPressPayload, sendDraft, slugify } = require("../src/wordpress-client");

const site = {
  id: "local",
  name: "Site de teste",
  niche: "jardinagem",
  language: "pt-BR",
  audience: "iniciantes",
  tone: "didático",
  categories: ["Guias"],
  topicIdeas: [],
  minWords: 100,
  editorialRules: [],
  wordpress: { endpoint: "http://localhost/articles", token: "secret", categoryIds: { Guias: 7 } },
};

const article = {
  title: "Como Cuidar de Plantas",
  excerpt: "Resumo.",
  content: "<p>Conteúdo.</p>",
};

test("gera instruções editoriais a partir do perfil sem fixar um nicho", () => {
  const prompt = buildPrompt(site, "Plantas em apartamento", "Guias");
  assert.match(prompt, /jardinagem/);
  assert.match(prompt, /Plantas em apartamento/);
  assert.match(prompt, /pt-BR/);
});

test("interpreta JSON de IA e recusa campos ou HTML inválidos", () => {
  assert.deepEqual(extractJson(JSON.stringify(article)), article);
  assert.throws(() => extractJson('{"title":"x","content":"<script>alert(1)</script>","excerpt":""}'), /HTML não permitido/);
  assert.throws(() => extractJson("sem JSON"), /JSON válido/);
});

test("chama a Responses API da Groq com modelo e extrai o texto gerado", async () => {
  let request;
  const result = await generateArticle(
    site,
    "Plantas em apartamento",
    "Guias",
    { apiKey: "test-key", model: "llama-3.3-70b-versatile", baseUrl: "https://api.groq.com/openai/v1" },
    async (url, options) => {
      request = { url, options };
      return new Response(JSON.stringify({
        output: [{ type: "message", content: [{ type: "output_text", text: JSON.stringify(article) }] }],
      }), { status: 200 });
    }
  );
  const body = JSON.parse(request.options.body);
  assert.equal(request.url, "https://api.groq.com/openai/v1/responses");
  assert.equal(request.options.headers.Authorization, "Bearer test-key");
  assert.equal(body.model, "llama-3.3-70b-versatile");
  assert.equal(body.max_output_tokens, 4000);
  assert.equal(body.store, false);
  assert.equal(body.instructions.includes("retorne apenas o JSON solicitado"), true);
  assert.equal(body.input[0].role, "user");
  assert.deepEqual(result, article);
});

test("expõe detalhes do erro da API sem reproduzir a chave", async () => {
  await assert.rejects(
    generateArticle(
      site,
      "Plantas em apartamento",
      "Guias",
      { apiKey: "test-secret", model: "llama-3.3-70b-versatile", baseUrl: "https://api.groq.com/openai/v1" },
      async () => new Response(JSON.stringify({
        error: { message: "Invalid request", code: "invalid_request", secret: "test-secret" },
      }), {
        status: 400,
        headers: { "x-request-id": "req-test-123" },
      })
    ),
    (error) => {
      assert.match(error.message, /HTTP 400: Invalid request \(invalid_request\)/);
      assert.match(error.message, /request ID: req-test-123/);
      assert.doesNotMatch(error.message, /test-secret/);
      return true;
    }
  );
});

test("mostra resposta de erro não JSON em vez de somente Bad Request", async () => {
  await assert.rejects(
    generateArticle(
      site,
      "Plantas em apartamento",
      "Guias",
      { apiKey: "test-secret", model: "llama-3.3-70b-versatile", baseUrl: "https://api.groq.com/openai/v1" },
      async () => new Response("Proxy error: invalid request with test-secret", {
        status: 400,
        statusText: "Bad Request",
      })
    ),
    (error) => {
      assert.match(error.message, /Proxy error: invalid request with \[redacted\]/);
      assert.doesNotMatch(error.message, /test-secret/);
      return true;
    }
  );
});

test("mostra corpo JSON de erro quando o provedor retorna somente um código", async () => {
  await assert.rejects(
    generateArticle(
      site,
      "Plantas em apartamento",
      "Guias",
      { apiKey: "test-secret", model: "llama-3.3-70b-versatile", baseUrl: "https://api.groq.com/openai/v1" },
      async () => new Response(JSON.stringify({
        error: { code: "invalid-argument", argument: "temperature" },
      }), { status: 400 })
    ),
    (error) => {
      assert.match(error.message, /invalid-argument/);
      assert.match(error.message, /temperature/);
      assert.doesNotMatch(error.message, /test-secret/);
      return true;
    }
  );
});

test("valida o perfil WordPress e exige o token fora da configuração", () => {
  const fs = require("node:fs");
  const os = require("node:os");
  const path = require("node:path");
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "wp-auto-"));
  fs.mkdirSync(path.join(root, "config"));
  fs.writeFileSync(path.join(root, "config", "sites.json"), JSON.stringify({
    sites: { local: { ...site, wordpress: { endpoint: "http://localhost/articles", tokenEnv: "WP_LOCAL_TOKEN" } } },
  }));
  try {
    assert.equal(loadSiteConfig("local", root, { WP_LOCAL_TOKEN: "secret" }).wordpress.token, "secret");
    assert.throws(() => loadSiteConfig("local", root, {}), /WP_LOCAL_TOKEN/);
    assert.equal(loadSiteConfig("local", root, {}, { allowMissingToken: true }).wordpress.token, "");
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test("valida a configuração de provedor de IA compatível com OpenAI", () => {
  assert.deepEqual(loadAiConfig({
    AI_API_KEY: "key",
    AI_MODEL: "model",
    AI_BASE_URL: "https://api.example.test/v1/",
  }), {
    apiKey: "key",
    model: "model",
    baseUrl: "https://api.example.test/v1",
  });
});

test("cria payload de rascunho com identificador idempotente por site e tópico", () => {
  const date = new Date("2026-11-03T15:00:00Z");
  const payload = buildWordPressPayload(site, article, "Plantas em apartamento", date, 2, "Guias");
  assert.equal(payload.source_id, "wp-auto:local:2026-11-03:plantas-em-apartamento:2");
  assert.equal(payload.slug, "como-cuidar-de-plantas");
  assert.equal(payload.content, article.content);
  assert.equal(payload.category_id, 7);
});

test("envia ao WordPress por Bearer e confirma status draft", async () => {
  let options;
  const result = await sendDraft(
    site,
    article,
    "Plantas em apartamento",
    async (_url, requestOptions) => {
      options = requestOptions;
      return new Response(JSON.stringify({ id: 8, status: "draft", link: "http://localhost/edit/8" }), { status: 201 });
    },
    new Date("2026-11-03T15:00:00Z"),
    1,
    "Guias"
  );
  assert.equal(options.headers.Authorization, "Bearer secret");
  assert.deepEqual(result, { id: 8, link: "http://localhost/edit/8", duplicate: false });
});

test("trata source_id repetido como sucesso idempotente", async () => {
  const result = await sendDraft(
    site,
    article,
    "Plantas em apartamento",
    async () => new Response(JSON.stringify({
      code: "article_automation_bridge_duplicate",
      data: { status: 409, post_id: 8 },
    }), { status: 409 })
  );
  assert.deepEqual(result, { id: 8, link: null, duplicate: true });
});

test("parser da CLI valida opções", () => {
  assert.deepEqual(parseArgs(["--site", "loja", "--topic", "Tema", "--count", "2", "--dry-run"]), {
    site: "loja",
    count: 2,
    topic: "Tema",
    category: null,
    dryRun: true,
  });
  assert.throws(() => parseArgs(["--count", "0"]), /inteiro maior que zero/);
});

test("permite rodar o pipeline com dependências simuladas sem publicar", async () => {
  const calls = [];
  const fakeSite = {
    ...site,
    topicIdeas: ["Tema teste"],
  };
  await run(
    { site: "local", count: 1, topic: null, category: null, dryRun: false },
    {
      rootDir: ".",
      loadSiteConfig: () => fakeSite,
      loadAiConfig: () => ({ apiKey: "key", model: "model", baseUrl: "https://ai.test/v1" }),
      generateArticle: async () => ({ ...article, content: `<p>${"palavra ".repeat(120)}</p>` }),
      sendDraft: async (...args) => {
        calls.push(args);
        return { id: 8, link: null, duplicate: false };
      },
    }
  );
  assert.equal(calls.length, 1);
});

test("slugify remove acentos e símbolos", () => {
  assert.equal(slugify("Água, Plantas & Jardins"), "agua-plantas-jardins");
});
