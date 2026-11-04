function slugify(text) {
  return text
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 80);
}

function buildWordPressPayload(site, article, topic, runDate = new Date(), sequence = 1, category = "") {
  const sourceId = `wp-auto:${site.id}:${runDate.toISOString().slice(0, 10)}:${slugify(topic || category || article.title)}:${sequence}`;
  const payload = {
    title: article.title,
    content: article.content,
    excerpt: article.excerpt,
    slug: slugify(article.title),
    source_id: sourceId,
  };

  const categoryId = Number(site.wordpress.categoryIds?.[category]);
  if (Number.isInteger(categoryId) && categoryId > 0) {
    payload.category_id = categoryId;
  }
  return payload;
}

async function sendDraft(site, article, topic, fetchImpl = fetch, runDate = new Date(), sequence = 1, category = "") {
  const response = await fetchImpl(site.wordpress.endpoint, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${site.wordpress.token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(buildWordPressPayload(site, article, topic, runDate, sequence, category)),
  });

  const responseBody = await response.json().catch(() => null);
  if (
    response.status === 409 &&
    responseBody?.code === "article_automation_bridge_duplicate"
  ) {
    const existingId = Number(responseBody.data?.post_id);
    if (!Number.isInteger(existingId) || existingId < 1) {
      throw new Error("WordPress informou duplicidade sem retornar o ID do rascunho.");
    }
    return { id: existingId, link: null, duplicate: true };
  }

  if (!response.ok) {
    const detail = responseBody?.message || response.statusText || "erro desconhecido";
    throw new Error(`WordPress respondeu HTTP ${response.status}: ${detail}`);
  }

  const postId = Number(responseBody?.id);
  if (!Number.isInteger(postId) || postId < 1 || responseBody.status !== "draft") {
    throw new Error("WordPress não confirmou a criação de um rascunho válido.");
  }

  return {
    id: postId,
    link: typeof responseBody.link === "string" ? responseBody.link : null,
    duplicate: false,
  };
}

module.exports = { buildWordPressPayload, sendDraft, slugify };
