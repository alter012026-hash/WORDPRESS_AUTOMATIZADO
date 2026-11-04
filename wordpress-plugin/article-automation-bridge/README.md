# Article Automation Bridge

Plugin local para configurar a geração de artigos no wp-admin. Os artigos são
sempre criados como rascunhos; a revisão e qualquer publicação ou agendamento
posterior continuam manuais.

## Instalação

Copie esta pasta para `wp-content/plugins/article-automation-bridge` da
instalação WordPress usada pelo XAMPP e ative o plugin em **Plugins**. A tela
fica em **Configurações > Automação de artigos**.

O painel permite escolher Groq, OpenAI, Anthropic Claude, Google Gemini,
OpenRouter, Ollama local ou outro serviço compatível com a OpenAI Responses
API. Configure a chave e o ID de um modelo disponível no provedor escolhido.
As chaves são guardadas separadamente por provedor. Para Ollama, a URL base
padrão é `http://localhost:11434/v1`; para outro serviço compatível com
Responses, informe a URL base HTTPS terminada, por exemplo, em `/v1`.

O painel também configura tema/nicho, público, idioma, tom, categoria, temas
sugeridos, regras, tamanho mínimo, quantidade por execução, limite da fila de
revisão e frequência diária ou semanal. Somente Ollama pode usar HTTP, e apenas
em `localhost`.

## Agendamento local confiável

O plugin agenda os trabalhos usando WP-Cron. Por padrão, o WP-Cron depende de
visitas ao site e pode atrasar. Para executar mesmo sem visitas, configure uma
tarefa no Windows que acione o `wp-cron.php` a cada minuto.

Abra o PowerShell como o mesmo usuário que executa o XAMPP e rode:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\wordpress\wp-content\plugins\article-automation-bridge\scripts\register-windows-cron-task.ps1"
```

O script cria/atualiza a tarefa `WordPress Article Automation WP-Cron`. O
endereço padrão é `http://localhost/wordpress`; use `-SiteUrl` se a instalação
local estiver em outro endereço. O XAMPP e o Apache precisam estar iniciados
para os horários dispararem.

Para remover a tarefa:

```powershell
Unregister-ScheduledTask -TaskName "WordPress Article Automation WP-Cron" -Confirm:$false
```

## Segurança e operação

- As chaves dos provedores são guardadas nas opções do WordPress e não são
  exibidas no painel.
- Restrinja o painel a administradores; apenas usuários com `manage_options`
  podem salvar a configuração ou gerar artigos.
- Cada lote respeita o limite de rascunhos automatizados ainda pendentes de
  revisão. A geração pausa quando não há espaço na fila.
- O botão **Gerar rascunhos agora** pode consumir créditos do provedor
  selecionado.
- Desativar o plugin remove o agendamento WordPress. A tarefa do Windows pode
  permanecer instalada; remova-a pelo comando acima se não for mais necessária.
