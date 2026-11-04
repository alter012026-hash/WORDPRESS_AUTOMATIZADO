function buildPrompt(site, topic, category) {
  const rules = site.editorialRules.map((rule) => `- ${rule}`).join("\n");
  const topicInstruction = topic
    ? `Escreva um artigo sobre: "${topic}".`
    : `Escolha um tema útil e específico dentro de "${site.niche}" para a categoria "${category}".`;

  return `Crie um artigo original para o site "${site.name}".

Configuração editorial:
- Assunto/nicho: ${site.niche}
- Idioma: ${site.language}
- Público: ${site.audience}
- Tom: ${site.tone}
- Categoria: ${category}
- Mínimo de palavras no corpo: ${site.minWords}

${topicInstruction}

Regras editoriais:
${rules || "- Seja preciso, útil e claro."}

Retorne somente um objeto JSON válido, sem cercas Markdown, com estas chaves:
{
  "title": "Título do artigo",
  "excerpt": "Resumo curto para a página do WordPress",
  "content": "<p>Corpo completo em HTML semântico...</p>"
}

O corpo deve ter no mínimo ${site.minWords} palavras, começar com conteúdo HTML (não inclua H1; o título será exibido pelo WordPress), usar subtítulos, parágrafos e listas quando apropriado, e não incluir scripts, estilos, iframes ou Markdown. Não invente fontes ou fatos verificáveis.`;
}

function extractJson(text) {
  const trimmed = text.trim().replace(/^```(?:json)?\s*|\s*```$/gi, "");
  const start = trimmed.indexOf("{");
  const end = trimmed.lastIndexOf("}");
  if (start < 0 || end <= start) {
    throw new Error("O provedor de IA não retornou um objeto JSON válido.");
  }

  let article;
  try {
    article = JSON.parse(trimmed.slice(start, end + 1));
  } catch (error) {
    throw new Error(`Não foi possível interpretar o artigo gerado: ${error.message}`);
  }

  if (
    typeof article.title !== "string" ||
    typeof article.excerpt !== "string" ||
    typeof article.content !== "string" ||
    !article.title.trim() ||
    !article.content.trim()
  ) {
    throw new Error("O artigo da IA precisa conter title, excerpt e content não vazios.");
  }
  if (/<\s*(script|style|iframe)\b/i.test(article.content)) {
    throw new Error("O artigo gerado contém HTML não permitido.");
  }

  return {
    title: article.title.trim(),
    excerpt: article.excerpt.trim(),
    content: article.content.trim(),
  };
}

function getProviderErrorDetails(body, rawBody) {
  const error = body?.error;
  const message = [
    error?.message,
    body?.message,
    body?.detail,
  ].find((value) => typeof value === "string" && value.trim());
  const code = error?.code || error?.type || body?.code || body?.type;
  const errors = Array.isArray(body?.errors)
    ? body.errors.map((item) => typeof item === "string" ? item : item?.message).filter(Boolean).join("; ")
    : "";
  const context = [errors, code].filter(Boolean).join("; ");

  if (message) return `${message}${context ? ` (${context})` : ""}`;
  if (errors) return errors;
  return rawBody ? rawBody.slice(0, 500) : context;
}

async function generateArticle(site, topic, category, aiConfig, fetchImpl = fetch) {
  const response = await fetchImpl(`${aiConfig.baseUrl}/responses`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${aiConfig.apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model: aiConfig.model,
      temperature: 0.7,
      max_output_tokens: 4000,
      store: false,
      instructions: "Você é um redator editorial. Siga a configuração do site e retorne apenas o JSON solicitado.",
      input: [{ role: "user", content: buildPrompt(site, topic, category) }],
    }),
  });

  const rawBody = await response.text();
  let responseBody = null;
  try {
    responseBody = JSON.parse(rawBody);
  } catch (error) {
    if (!(error instanceof SyntaxError)) throw error;
  }
  if (!response.ok) {
    const requestId = response.headers.get("x-request-id")
      || response.headers.get("xai-request-id")
      || response.headers.get("request-id");
    const detail = (getProviderErrorDetails(responseBody, rawBody) || response.statusText || "erro desconhecido")
      .replaceAll(aiConfig.apiKey, "[redacted]");
    throw new Error(
      `O provedor de IA respondeu HTTP ${response.status}: ${detail}${requestId ? ` (request ID: ${requestId})` : ""}`
    );
  }

  const text = responseBody?.output
    ?.flatMap((item) => item.content || [])
    .filter((item) => item.type === "output_text")
    .map((item) => item.text)
    .join("");
  if (typeof text !== "string" || !text.trim()) {
    throw new Error("O provedor de IA retornou uma resposta vazia.");
  }

  return extractJson(text);
}

module.exports = { buildPrompt, extractJson, generateArticle };
