#!/usr/bin/env node
require("dotenv").config({ path: ".env.local" });

const fs = require("node:fs");
const path = require("node:path");
const { generateArticle } = require("./article-generator");
const { loadAiConfig, loadSiteConfig } = require("./config");
const { sendDraft } = require("./wordpress-client");

function parseArgs(args) {
  const options = { site: "local", count: 1, topic: null, category: null, dryRun: false };
  for (let index = 0; index < args.length; index += 1) {
    const argument = args[index];
    if (argument === "--dry-run") {
      options.dryRun = true;
      continue;
    }
    if (!["--site", "--count", "--topic", "--category"].includes(argument)) {
      throw new Error(`Argumento desconhecido: ${argument}`);
    }

    const value = args[index + 1];
    if (!value || value.startsWith("--")) {
      throw new Error(`Falta o valor para ${argument}.`);
    }
    index += 1;

    if (argument === "--site") options.site = value;
    if (argument === "--topic") options.topic = value;
    if (argument === "--category") options.category = value;
    if (argument === "--count") {
      options.count = Number(value);
      if (!Number.isInteger(options.count) || options.count < 1) {
        throw new Error("--count precisa ser um inteiro maior que zero.");
      }
    }
  }
  return options;
}

function pick(items, index) {
  return items[index % items.length];
}

async function run(options, dependencies = {}) {
  const rootDir = dependencies.rootDir || path.resolve(__dirname, "..");
  const site = (dependencies.loadSiteConfig || loadSiteConfig)(
    options.site,
    rootDir,
    process.env,
    { allowMissingToken: options.dryRun }
  );
  const aiConfig = (dependencies.loadAiConfig || loadAiConfig)();
  const generate = dependencies.generateArticle || generateArticle;
  const publish = dependencies.sendDraft || sendDraft;

  for (let index = 0; index < options.count; index += 1) {
    const category = options.category || pick(site.categories, index);
    const topic = options.topic || pick(site.topicIdeas, index) || null;
    console.log(`Gerando ${index + 1}/${options.count} para "${site.name}" (${category})...`);

    const article = await generate(site, topic, category, aiConfig);
    const words = article.content.replace(/<[^>]*>/g, " ").trim().split(/\s+/).filter(Boolean).length;
    if (words < site.minWords) {
      throw new Error(`O artigo tem ${words} palavras; o mínimo configurado é ${site.minWords}.`);
    }

    if (options.dryRun) {
      console.log(JSON.stringify({ title: article.title, excerpt: article.excerpt, content: article.content }, null, 2));
      continue;
    }

    const result = await publish(site, article, topic, fetch, new Date(), index + 1, category);
    console.log(
      `Rascunho WordPress ${result.duplicate ? "já existente" : "criado"}: #${result.id}` +
        (result.link ? ` (${result.link})` : "")
    );
  }
}

if (require.main === module) {
  run(parseArgs(process.argv.slice(2))).catch((error) => {
    console.error(`Erro: ${error.message}`);
    process.exitCode = 1;
  });
}

module.exports = { parseArgs, run };
