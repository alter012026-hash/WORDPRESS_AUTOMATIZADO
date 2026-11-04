# WordPress Automatizado

Motor local e configurável para gerar artigos com IA para diferentes sites e
assuntos e enviá-los ao WordPress como **rascunhos**, para revisão humana.
Não publica automaticamente nem depende de um nicho específico.

Para a arquitetura completa, os dois fluxos de geração, a configuração,
agendamento, segurança e solução de problemas, consulte
[Fluxo e funcionamento](./docs/FLUXO-E-FUNCIONAMENTO.md).

## Requisitos

- Node.js 20 ou superior
- WordPress acessível pela máquina local
- Plugin Article Automation Bridge ativo no WordPress
- API de IA com suporte ao endpoint Responses para a CLI (Groq por padrão)

## Configuração local

1. Instale e ative o plugin WordPress incluído em `wordpress-plugin/`. Para
   configurar a automação pelo wp-admin, consulte o
   [README do plugin](./wordpress-plugin/article-automation-bridge/README.md).
   No painel do plugin, é possível selecionar Groq, OpenAI, Anthropic Claude,
   Google Gemini, OpenRouter, Ollama local ou outro serviço compatível com
   OpenAI Responses API.
2. No `wp-config.php` do WordPress, configure um token compartilhado:

   ```php
   define( 'ARTICLE_AUTOMATION_BRIDGE_TOKEN', 'seu-token-local' );
   define( 'ARTICLE_AUTOMATION_BRIDGE_AUTHOR_ID', 1 );
   ```

3. Copie `config/sites.example.json` para `config/sites.json` e configure um
   perfil para cada site. Cada perfil define o assunto, idioma, público, tom,
   categorias, tamanho mínimo, endpoint WordPress e o nome da variável de
   ambiente que contém o token daquele site. Para associar as categorias a
   categorias já existentes no WordPress, informe seus IDs em
   `wordpress.categoryIds`.
4. Copie `.env.example` para `.env.local`. Para usar Groq, crie uma chave em
   [console.groq.com/keys](https://console.groq.com/keys) e informe-a em
   `AI_API_KEY`. O endereço `https://api.groq.com/openai/v1` e o modelo
   `openai/gpt-oss-120b` já ficam preenchidos. Consulte a página de modelos
   da Groq para identificar modelos ativos e disponíveis para sua conta.
5. Configure `WP_LOCAL_TOKEN` em `.env.local` com o mesmo token definido no
   `wp-config.php`. Não compartilhe esse arquivo nem envie chaves pelo chat.
6. Instale dependências e valide a configuração:

   ```powershell
   npm install
   npm test
   ```

## Gerar um rascunho

```powershell
npm run generate -- --site local --topic "Tema do artigo"
```

Se `topicIdeas` tiver temas configurados, pode omitir `--topic`. Para conferir
o conteúdo gerado sem chamar o WordPress:

```powershell
npm run generate -- --site local --topic "Tema do artigo" --dry-run
```

`--category` escolhe uma categoria configurada e `--count` define quantos
artigos gerar. Cada execução envia artigos validados como rascunhos; a revisão e
a publicação permanecem manuais. O `source_id` evita duplicações no caso de
reenvio do mesmo site, tema, dia e posição na execução.

No XAMPP com permalinks padrão, use o endpoint com `?rest_route=` mostrado no
perfil de exemplo. Em um site com permalinks REST habilitados, pode usar o
endpoint `/wp-json/article-automation/v1/articles`.

O cliente usa a Responses API compatível da Groq em
`https://api.groq.com/openai/v1/responses`. Para trocar de modelo, edite
`AI_MODEL` usando um identificador disponível na página de
[modelos da Groq](https://console.groq.com/docs/models).

## Vários sites

Adicione perfis em `config/sites.json`, por exemplo `loja`, `revista` e
`institucional`. Cada perfil pode apontar para um WordPress diferente e usar
uma variável de token distinta. Os segredos ficam em `.env.local`, ignorado
pelo Git; não os adicione ao repositório.
