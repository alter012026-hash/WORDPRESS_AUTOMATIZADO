const fs = require("node:fs");
const path = require("node:path");

function loadSiteConfig(siteId, rootDir = process.cwd(), env = process.env, options = {}) {
  const configPath = path.join(rootDir, "config", "sites.json");
  if (!fs.existsSync(configPath)) {
    throw new Error(
      `Arquivo ${configPath} não encontrado. Copie config/sites.example.json para config/sites.json e configure seu site.`
    );
  }

  let config;
  try {
    config = JSON.parse(fs.readFileSync(configPath, "utf8"));
  } catch (error) {
    throw new Error(`Não foi possível ler ${configPath}: ${error.message}`);
  }

  const site = config?.sites?.[siteId];
  if (!site || typeof site !== "object") {
    throw new Error(`Perfil de site "${siteId}" não encontrado em ${configPath}.`);
  }

  const requiredFields = ["name", "niche", "language", "audience", "tone"];
  for (const field of requiredFields) {
    if (typeof site[field] !== "string" || !site[field].trim()) {
      throw new Error(`O perfil "${siteId}" precisa configurar "${field}".`);
    }
  }

  if (!Number.isInteger(site.minWords) || site.minWords < 100) {
    throw new Error(`O perfil "${siteId}" precisa definir minWords como inteiro >= 100.`);
  }
  if (!Array.isArray(site.categories) || site.categories.length === 0) {
    throw new Error(`O perfil "${siteId}" precisa ter ao menos uma categoria.`);
  }
  if (!Array.isArray(site.topicIdeas)) {
    throw new Error(`O campo topicIdeas do perfil "${siteId}" precisa ser uma lista.`);
  }
  if (!Array.isArray(site.editorialRules)) {
    throw new Error(`O campo editorialRules do perfil "${siteId}" precisa ser uma lista.`);
  }
  if (!site.wordpress || typeof site.wordpress.endpoint !== "string") {
    throw new Error(`O perfil "${siteId}" precisa configurar wordpress.endpoint.`);
  }

  const endpoint = new URL(site.wordpress.endpoint);
  if (
    endpoint.protocol !== "https:" &&
    !["localhost", "127.0.0.1", "::1"].includes(endpoint.hostname)
  ) {
    throw new Error("O endpoint WordPress precisa usar HTTPS fora do ambiente local.");
  }

  const tokenEnv = site.wordpress.tokenEnv;
  if (typeof tokenEnv !== "string" || !/^[A-Z][A-Z0-9_]*$/.test(tokenEnv)) {
    throw new Error(`O perfil "${siteId}" precisa indicar wordpress.tokenEnv válido.`);
  }
  if (!env[tokenEnv] && !options.allowMissingToken) {
    throw new Error(`A variável ${tokenEnv} não está configurada.`);
  }

  return {
    id: siteId,
    ...site,
    wordpress: {
      ...site.wordpress,
      token: env[tokenEnv] || "",
    },
  };
}

function loadAiConfig(env = process.env) {
  const apiKey = env.AI_API_KEY?.trim();
  const model = env.AI_MODEL?.trim();
  const baseUrl = (env.AI_BASE_URL || "https://api.openai.com/v1").trim().replace(/\/+$/, "");

  if (!apiKey) throw new Error("A variável AI_API_KEY não está configurada.");
  if (!model) throw new Error("A variável AI_MODEL não está configurada.");

  const url = new URL(baseUrl);
  if (url.protocol !== "https:" && !["localhost", "127.0.0.1", "::1"].includes(url.hostname)) {
    throw new Error("AI_BASE_URL precisa usar HTTPS fora do ambiente local.");
  }

  return { apiKey, model, baseUrl };
}

module.exports = { loadAiConfig, loadSiteConfig };
