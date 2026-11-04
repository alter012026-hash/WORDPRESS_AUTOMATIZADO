<?php
/**
 * WordPress administration and scheduled generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARTICLE_AUTOMATION_BRIDGE_SETTINGS', 'article_automation_bridge_settings' );
define( 'ARTICLE_AUTOMATION_BRIDGE_CRON_HOOK', 'article_automation_bridge_generate_scheduled' );

function article_automation_bridge_default_settings() {
	return array(
		'provider'      => 'groq',
		'api_key'       => '',
		'api_keys'      => array(),
		'model'         => 'openai/gpt-oss-120b',
		'base_url'      => '',
		'site_name'     => get_bloginfo( 'name' ),
		'theme'         => '',
		'audience'      => '',
		'language'      => 'pt-BR',
		'tone'          => 'Informativo, claro e natural',
		'min_words'     => 800,
		'category_id'   => (int) get_option( 'default_category', 1 ),
		'topics'        => '',
		'rules'         => "Não invente fatos, números, citações ou fontes.\nUse HTML semântico compatível com o editor do WordPress.",
		'batch_size'    => 1,
		'max_pending'   => 20,
		'schedule_on'   => 0,
		'frequency'     => 'daily',
		'day_of_week'   => '1',
		'time'          => '09:00',
	);
}

function article_automation_bridge_provider_options() {
	return array(
		'groq'      => 'Groq',
		'openai'    => 'OpenAI',
		'anthropic' => 'Anthropic Claude',
		'google'    => 'Google Gemini',
		'openrouter' => 'OpenRouter',
		'ollama'    => 'Ollama local',
		'custom'    => 'Outro compatível com OpenAI Responses API',
	);
}

function article_automation_bridge_get_settings() {
	$settings = wp_parse_args(
		get_option( ARTICLE_AUTOMATION_BRIDGE_SETTINGS, array() ),
		article_automation_bridge_default_settings()
	);
	if ( ! is_array( $settings['api_keys'] ) ) {
		$settings['api_keys'] = array();
	}
	if ( ! empty( $settings['api_key'] ) && empty( $settings['api_keys']['groq'] ) ) {
		$settings['api_keys']['groq'] = $settings['api_key'];
	}
	$settings['api_key'] = isset( $settings['api_keys'][ $settings['provider'] ] ) ? $settings['api_keys'][ $settings['provider'] ] : '';
	return $settings;
}

function article_automation_bridge_admin_menu() {
	add_options_page(
		'Automação de artigos',
		'Automação de artigos',
		'manage_options',
		'article-automation-bridge',
		'article_automation_bridge_render_settings_page'
	);
}
add_action( 'admin_menu', 'article_automation_bridge_admin_menu' );

function article_automation_bridge_enqueue_admin_assets( $hook_suffix ) {
	if ( 'settings_page_article-automation-bridge' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'article-automation-bridge-admin',
		plugins_url( 'assets/admin.css', __FILE__ ),
		array(),
		'1.2.1'
	);
	wp_enqueue_script(
		'article-automation-bridge-admin',
		plugins_url( 'assets/admin.js', __FILE__ ),
		array(),
		'1.2.1',
		true
	);
}
add_action( 'admin_enqueue_scripts', 'article_automation_bridge_enqueue_admin_assets' );

function article_automation_bridge_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'article-automation-bridge' ) );
	}

	$settings    = article_automation_bridge_get_settings();
	$categories  = get_categories( array( 'hide_empty' => false ) );
	$pending     = article_automation_bridge_pending_count();
	$next_run    = wp_next_scheduled( ARTICLE_AUTOMATION_BRIDGE_CRON_HOOK );
	$notice      = isset( $_GET['article_automation_notice'] ) ? sanitize_key( wp_unslash( $_GET['article_automation_notice'] ) ) : '';
	$last_error  = get_transient( 'article_automation_bridge_last_error' );
	$providers   = article_automation_bridge_provider_options();
	?>
	<div class="wrap aab-wrap">
		<header class="aab-header">
			<div>
				<p class="aab-eyebrow"><?php esc_html_e( 'CENTRAL DE CONTEÚDO', 'article-automation-bridge' ); ?></p>
				<h1><?php esc_html_e( 'Automação de artigos', 'article-automation-bridge' ); ?></h1>
				<p class="aab-intro"><?php esc_html_e( 'Configure a inteligência artificial, organize sua linha editorial e prepare rascunhos para revisão.', 'article-automation-bridge' ); ?></p>
			</div>
			<span class="aab-review-badge"><span aria-hidden="true"></span><?php esc_html_e( 'Publicação sempre manual', 'article-automation-bridge' ); ?></span>
		</header>

		<?php if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configurações salvas.', 'article-automation-bridge' ); ?></p></div>
		<?php elseif ( 'generated' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Geração concluída. Confira os novos rascunhos em Posts > Todos os posts.', 'article-automation-bridge' ); ?></p></div>
		<?php elseif ( 'error' === $notice ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $last_error ? $last_error : __( 'A geração não foi concluída. Nenhum post será publicado automaticamente.', 'article-automation-bridge' ) ); ?></p></div>
		<?php endif; ?>

		<div class="aab-overview" aria-label="<?php esc_attr_e( 'Resumo da automação', 'article-automation-bridge' ); ?>">
			<div class="aab-stat-card">
				<span class="aab-stat-label"><?php esc_html_e( 'Fila de revisão', 'article-automation-bridge' ); ?></span>
				<strong><?php echo esc_html( (int) $pending ); ?><span> / <?php echo esc_html( (int) $settings['max_pending'] ); ?></span></strong>
				<p><?php esc_html_e( 'rascunhos aguardando revisão', 'article-automation-bridge' ); ?></p>
			</div>
			<div class="aab-stat-card">
				<span class="aab-stat-label"><?php esc_html_e( 'Provedor ativo', 'article-automation-bridge' ); ?></span>
				<strong class="aab-stat-text"><?php echo esc_html( isset( $providers[ $settings['provider'] ] ) ? $providers[ $settings['provider'] ] : $providers['groq'] ); ?></strong>
				<p><?php echo esc_html( $settings['model'] ); ?></p>
			</div>
			<div class="aab-stat-card">
				<span class="aab-stat-label"><?php esc_html_e( 'Agendamento', 'article-automation-bridge' ); ?></span>
				<strong class="aab-stat-text"><?php echo ! empty( $settings['schedule_on'] ) ? esc_html__( 'Ativo', 'article-automation-bridge' ) : esc_html__( 'Desativado', 'article-automation-bridge' ); ?></strong>
				<p>
					<?php
					if ( $next_run ) {
						echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) );
					} elseif ( $settings['schedule_on'] ) {
						esc_html_e( 'Próxima execução ainda não registrada', 'article-automation-bridge' );
					} else {
						esc_html_e( 'Sem execuções programadas', 'article-automation-bridge' );
					}
					?>
				</p>
			</div>
		</div>

		<form class="aab-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="article_automation_bridge_save_settings">
			<?php wp_nonce_field( 'article_automation_bridge_save_settings' ); ?>
			<section class="aab-card" aria-labelledby="aab-ai-heading">
				<div class="aab-card-heading">
					<span class="aab-step">01</span>
					<div><h2 id="aab-ai-heading"><?php esc_html_e( 'Inteligência artificial', 'article-automation-bridge' ); ?></h2><p><?php esc_html_e( 'Escolha o provedor e conecte o modelo que vai criar seus rascunhos.', 'article-automation-bridge' ); ?></p></div>
				</div>
				<div class="aab-field-grid">
					<div class="aab-field aab-field-wide">
						<label for="provider"><?php esc_html_e( 'Provedor de IA', 'article-automation-bridge' ); ?></label>
						<select id="provider" name="settings[provider]">
							<?php foreach ( $providers as $provider_id => $provider_label ) : ?>
								<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $settings['provider'], $provider_id ); ?>><?php echo esc_html( $provider_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Este provedor será usado nas gerações manuais e agendadas.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field aab-api-key-field">
						<label for="api_key"><?php esc_html_e( 'Chave de API', 'article-automation-bridge' ); ?></label>
						<input type="password" id="api_key" name="settings[api_key]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Cole sua chave de API', 'article-automation-bridge' ); ?>">
						<p class="description"><?php echo $settings['api_key'] ? esc_html__( 'Chave salva. Deixe em branco para mantê-la. Cada provedor guarda sua própria chave.', 'article-automation-bridge' ) : esc_html__( 'A chave será guardada neste WordPress e não será exibida novamente.', 'article-automation-bridge' ); ?> <?php esc_html_e( 'Ollama local não exige chave.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field">
						<label for="model"><?php esc_html_e( 'Modelo', 'article-automation-bridge' ); ?></label>
						<input type="text" id="model" name="settings[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" required>
						<p class="description"><?php esc_html_e( 'Informe o identificador de um modelo disponível na sua conta.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field aab-provider-url-field">
						<label for="base_url"><?php esc_html_e( 'URL base da API', 'article-automation-bridge' ); ?></label>
						<input type="url" id="base_url" name="settings[base_url]" value="<?php echo esc_attr( $settings['base_url'] ); ?>" placeholder="http://localhost:11434/v1">
						<p class="description"><?php esc_html_e( 'Ollama usa http://localhost:11434/v1 por padrão. Para outro serviço, informe uma URL HTTPS compatível com Responses API.', 'article-automation-bridge' ); ?></p>
					</div>
				</div>
			</section>

			<section class="aab-card" aria-labelledby="aab-editorial-heading">
				<div class="aab-card-heading">
					<span class="aab-step">02</span>
					<div><h2 id="aab-editorial-heading"><?php esc_html_e( 'Identidade editorial', 'article-automation-bridge' ); ?></h2><p><?php esc_html_e( 'Dê contexto à IA para manter os artigos alinhados ao seu site e ao seu público.', 'article-automation-bridge' ); ?></p></div>
				</div>
				<div class="aab-field-grid">
					<div class="aab-field">
						<label for="site_name"><?php esc_html_e( 'Nome do site', 'article-automation-bridge' ); ?></label>
						<input type="text" id="site_name" name="settings[site_name]" value="<?php echo esc_attr( $settings['site_name'] ); ?>" required>
					</div>
					<div class="aab-field">
						<label for="theme"><?php esc_html_e( 'Tema ou nicho editorial', 'article-automation-bridge' ); ?></label>
						<input type="text" id="theme" name="settings[theme]" value="<?php echo esc_attr( $settings['theme'] ); ?>" required>
					</div>
					<div class="aab-field">
						<label for="audience"><?php esc_html_e( 'Público-alvo', 'article-automation-bridge' ); ?></label>
						<input type="text" id="audience" name="settings[audience]" value="<?php echo esc_attr( $settings['audience'] ); ?>" required>
					</div>
					<div class="aab-field">
						<label for="language"><?php esc_html_e( 'Idioma', 'article-automation-bridge' ); ?></label>
						<input type="text" id="language" name="settings[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" required>
					</div>
					<div class="aab-field">
						<label for="tone"><?php esc_html_e( 'Tom de escrita', 'article-automation-bridge' ); ?></label>
						<input type="text" id="tone" name="settings[tone]" value="<?php echo esc_attr( $settings['tone'] ); ?>" required>
					</div>
					<div class="aab-field">
						<label for="category_id"><?php esc_html_e( 'Categoria dos rascunhos', 'article-automation-bridge' ); ?></label>
						<select id="category_id" name="settings[category_id]">
							<?php foreach ( $categories as $category ) : ?>
								<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( (int) $settings['category_id'], (int) $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aab-field">
						<label for="min_words"><?php esc_html_e( 'Mínimo de palavras', 'article-automation-bridge' ); ?></label>
						<input type="number" id="min_words" name="settings[min_words]" min="100" max="10000" value="<?php echo esc_attr( $settings['min_words'] ); ?>" required>
						<p class="description"><?php esc_html_e( 'Entre 100 e 10.000 palavras.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field aab-field-wide">
						<label for="topics"><?php esc_html_e( 'Temas sugeridos', 'article-automation-bridge' ); ?></label>
						<textarea id="topics" name="settings[topics]" rows="4"><?php echo esc_textarea( $settings['topics'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Um tema por linha. Se deixar vazio, a IA sugere assuntos dentro do nicho configurado.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field aab-field-wide">
						<label for="rules"><?php esc_html_e( 'Regras editoriais', 'article-automation-bridge' ); ?></label>
						<textarea id="rules" name="settings[rules]" rows="4"><?php echo esc_textarea( $settings['rules'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Adicione uma orientação por linha, como fontes permitidas ou termos a evitar.', 'article-automation-bridge' ); ?></p>
					</div>
				</div>
			</section>

			<section class="aab-card" aria-labelledby="aab-workflow-heading">
				<div class="aab-card-heading">
					<span class="aab-step">03</span>
					<div><h2 id="aab-workflow-heading"><?php esc_html_e( 'Fila e agendamento', 'article-automation-bridge' ); ?></h2><p><?php esc_html_e( 'Controle o ritmo de geração e o volume de conteúdo aguardando sua revisão.', 'article-automation-bridge' ); ?></p></div>
				</div>
				<div class="aab-field-grid">
					<div class="aab-field">
						<label for="batch_size"><?php esc_html_e( 'Artigos por execução', 'article-automation-bridge' ); ?></label>
						<input type="number" id="batch_size" name="settings[batch_size]" min="1" max="5" value="<?php echo esc_attr( $settings['batch_size'] ); ?>">
						<p class="description"><?php esc_html_e( 'De 1 a 5 rascunhos por lote.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field">
						<label for="max_pending"><?php esc_html_e( 'Limite de rascunhos pendentes', 'article-automation-bridge' ); ?></label>
						<input type="number" id="max_pending" name="settings[max_pending]" min="1" max="500" value="<?php echo esc_attr( $settings['max_pending'] ); ?>">
						<p class="description"><?php esc_html_e( 'A geração pausa quando a fila atingir esse limite.', 'article-automation-bridge' ); ?></p>
					</div>
					<div class="aab-field aab-field-wide">
						<label class="aab-checkbox">
							<input type="checkbox" name="settings[schedule_on]" value="1" <?php checked( $settings['schedule_on'] ); ?>>
							<span><strong><?php esc_html_e( 'Ativar geração agendada', 'article-automation-bridge' ); ?></strong><small><?php esc_html_e( 'Os artigos agendados também serão salvos apenas como rascunhos.', 'article-automation-bridge' ); ?></small></span>
						</label>
					</div>
					<div class="aab-field">
						<label for="frequency"><?php esc_html_e( 'Frequência', 'article-automation-bridge' ); ?></label>
						<select id="frequency" name="settings[frequency]">
							<option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Diária', 'article-automation-bridge' ); ?></option>
							<option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Semanal', 'article-automation-bridge' ); ?></option>
						</select>
					</div>
					<div class="aab-field aab-weekday-field">
						<label for="day_of_week"><?php esc_html_e( 'Dia da semana', 'article-automation-bridge' ); ?></label>
						<select id="day_of_week" name="settings[day_of_week]">
							<?php
							$days = array(
								'1' => __( 'Segunda-feira', 'article-automation-bridge' ),
								'2' => __( 'Terça-feira', 'article-automation-bridge' ),
								'3' => __( 'Quarta-feira', 'article-automation-bridge' ),
								'4' => __( 'Quinta-feira', 'article-automation-bridge' ),
								'5' => __( 'Sexta-feira', 'article-automation-bridge' ),
								'6' => __( 'Sábado', 'article-automation-bridge' ),
								'0' => __( 'Domingo', 'article-automation-bridge' ),
							);
							foreach ( $days as $day => $label ) :
								?>
								<option value="<?php echo esc_attr( $day ); ?>" <?php selected( $settings['day_of_week'], $day ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aab-field">
						<label for="time"><?php esc_html_e( 'Horário local do WordPress', 'article-automation-bridge' ); ?></label>
						<input type="time" id="time" name="settings[time]" value="<?php echo esc_attr( $settings['time'] ); ?>">
					</div>
					<p class="aab-schedule-note aab-field-wide"><?php esc_html_e( 'O WP-Cron precisa ser acionado pelo site ou pelo Agendador do Windows. Consulte as instruções do plugin se a execução local atrasar.', 'article-automation-bridge' ); ?></p>
				</div>
			</section>

			<div class="aab-form-actions">
				<p><?php esc_html_e( 'Salve as alterações antes de iniciar uma nova geração.', 'article-automation-bridge' ); ?></p>
				<?php submit_button( __( 'Salvar configurações', 'article-automation-bridge' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<section class="aab-card aab-generate-card" aria-labelledby="aab-generate-heading">
			<div class="aab-generate-copy">
				<span class="aab-step aab-step-accent"><?php esc_html_e( 'AÇÃO MANUAL', 'article-automation-bridge' ); ?></span>
				<h2 id="aab-generate-heading"><?php esc_html_e( 'Pronto para criar rascunhos?', 'article-automation-bridge' ); ?></h2>
				<p><?php esc_html_e( 'A geração usa o provedor selecionado, pode consumir créditos e respeita a quantidade e o limite da fila configurados acima.', 'article-automation-bridge' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="article_automation_bridge_generate_now">
				<?php wp_nonce_field( 'article_automation_bridge_generate_now' ); ?>
				<?php submit_button( __( 'Gerar rascunhos agora', 'article-automation-bridge' ), 'primary', 'submit', false ); ?>
			</form>
		</section>

		<?php if ( $settings['schedule_on'] ) : ?>
			<p class="aab-cron-note"><?php esc_html_e( 'A agenda do WordPress está ativa. Para horários confiáveis em instalações locais, configure o Agendador do Windows conforme o README do plugin.', 'article-automation-bridge' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

function article_automation_bridge_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Acesso não autorizado.', 'article-automation-bridge' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'article_automation_bridge_save_settings' );

	$input    = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
	$settings = article_automation_bridge_sanitize_settings( $input );
	update_option( ARTICLE_AUTOMATION_BRIDGE_SETTINGS, $settings, false );
	article_automation_bridge_schedule_generation();

	wp_safe_redirect( add_query_arg( 'article_automation_notice', 'saved', admin_url( 'options-general.php?page=article-automation-bridge' ) ) );
	exit;
}
add_action( 'admin_post_article_automation_bridge_save_settings', 'article_automation_bridge_handle_save_settings' );

function article_automation_bridge_sanitize_settings( $input ) {
	$defaults = article_automation_bridge_default_settings();
	$previous = article_automation_bridge_get_settings();
	$settings = $defaults;

	$provider = isset( $input['provider'] ) && is_scalar( $input['provider'] ) ? sanitize_key( $input['provider'] ) : $previous['provider'];
	if ( ! array_key_exists( $provider, article_automation_bridge_provider_options() ) ) {
		wp_die( esc_html__( 'O provedor de IA selecionado não é válido.', 'article-automation-bridge' ), '', array( 'response' => 400 ) );
	}
	$settings['provider'] = $provider;
	$api_key = isset( $input['api_key'] ) && is_scalar( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
	$settings['api_keys'] = $previous['api_keys'];
	if ( 'ollama' === $provider ) {
		unset( $settings['api_keys']['ollama'] );
	} elseif ( '' !== $api_key ) {
		$settings['api_keys'][ $provider ] = sanitize_text_field( $api_key );
	}
	$settings['api_key'] = isset( $settings['api_keys'][ $provider ] ) ? $settings['api_keys'][ $provider ] : '';
	$settings['model']       = article_automation_bridge_input_text( $input, 'model', $defaults['model'] );
	$base_url = isset( $input['base_url'] ) && is_scalar( $input['base_url'] ) ? trim( (string) $input['base_url'] ) : '';
	$settings['base_url'] = '' !== $base_url ? esc_url_raw( $base_url, array( 'http', 'https' ) ) : '';
	if ( in_array( $provider, array( 'ollama', 'custom' ), true ) ) {
		$settings['base_url'] = article_automation_bridge_validate_base_url( $settings['base_url'], $provider );
	}
	$settings['site_name']   = article_automation_bridge_input_text( $input, 'site_name', $defaults['site_name'] );
	$settings['theme']       = article_automation_bridge_input_text( $input, 'theme', '' );
	$settings['audience']    = article_automation_bridge_input_text( $input, 'audience', '' );
	$settings['language']    = article_automation_bridge_input_text( $input, 'language', 'pt-BR' );
	$settings['tone']        = article_automation_bridge_input_text( $input, 'tone', $defaults['tone'] );
	$settings['min_words']   = isset( $input['min_words'] ) && is_scalar( $input['min_words'] ) ? min( 10000, max( 100, absint( $input['min_words'] ) ) ) : $defaults['min_words'];
	$settings['category_id'] = isset( $input['category_id'] ) && is_scalar( $input['category_id'] ) ? absint( $input['category_id'] ) : $defaults['category_id'];
	$settings['topics']      = isset( $input['topics'] ) && is_scalar( $input['topics'] ) ? sanitize_textarea_field( $input['topics'] ) : '';
	$settings['rules']       = isset( $input['rules'] ) && is_scalar( $input['rules'] ) ? sanitize_textarea_field( $input['rules'] ) : $defaults['rules'];
	$settings['batch_size']  = isset( $input['batch_size'] ) && is_scalar( $input['batch_size'] ) ? min( 5, max( 1, absint( $input['batch_size'] ) ) ) : 1;
	$settings['max_pending'] = isset( $input['max_pending'] ) && is_scalar( $input['max_pending'] ) ? min( 500, max( 1, absint( $input['max_pending'] ) ) ) : 20;
	$settings['schedule_on'] = isset( $input['schedule_on'] ) && is_scalar( $input['schedule_on'] ) ? 1 : 0;
	$settings['frequency']   = isset( $input['frequency'] ) && is_scalar( $input['frequency'] ) && 'weekly' === $input['frequency'] ? 'weekly' : 'daily';
	$settings['day_of_week'] = isset( $input['day_of_week'] ) && is_scalar( $input['day_of_week'] ) && in_array( (string) $input['day_of_week'], array( '0', '1', '2', '3', '4', '5', '6' ), true ) ? (string) $input['day_of_week'] : '1';
	$time = isset( $input['time'] ) && is_scalar( $input['time'] ) ? (string) $input['time'] : '';
	$settings['time']        = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '09:00';

	$category = get_term( $settings['category_id'], 'category' );
	if ( ! $category || is_wp_error( $category ) ) {
		$settings['category_id'] = (int) get_option( 'default_category', 1 );
	}

	return $settings;
}

function article_automation_bridge_validate_base_url( $base_url, $provider ) {
	if ( 'ollama' === $provider && '' === $base_url ) {
		return 'http://localhost:11434/v1';
	}
	$parts = wp_parse_url( $base_url );
	$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( trim( $parts['host'], '[]' ) ) : '';
	$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	if (
		! is_array( $parts ) ||
		'' === $host ||
		! isset( $parts['scheme'] ) ||
		( 'https' !== strtolower( $parts['scheme'] ) && !( 'ollama' === $provider && 'http' === strtolower( $parts['scheme'] ) && $local ) ) ||
		isset( $parts['user'] ) ||
		isset( $parts['pass'] ) ||
		isset( $parts['query'] ) ||
		isset( $parts['fragment'] )
	) {
		wp_die( esc_html__( 'Informe uma URL base HTTPS válida. Ollama pode usar HTTP somente em localhost.', 'article-automation-bridge' ), '', array( 'response' => 400 ) );
	}
	return untrailingslashit( $base_url );
}

function article_automation_bridge_input_text( $input, $key, $fallback ) {
	if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
		return $fallback;
	}
	return sanitize_text_field( (string) $input[ $key ] );
}

function article_automation_bridge_pending_count() {
	$query = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'draft', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_article_automation_source_id',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	return (int) $query->found_posts;
}

function article_automation_bridge_schedule_generation() {
	article_automation_bridge_clear_generation_schedule();
	$settings = article_automation_bridge_get_settings();
	if ( empty( $settings['schedule_on'] ) ) {
		return;
	}

	$timezone = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $timezone );
	list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['time'] ) );
	$next = $now->setTime( $hour, $minute, 0 );

	if ( 'weekly' === $settings['frequency'] ) {
		$days_ahead = ( (int) $settings['day_of_week'] - (int) $next->format( 'w' ) + 7 ) % 7;
		$next       = $next->modify( '+' . $days_ahead . ' days' );
		if ( $next <= $now ) {
			$next = $next->modify( '+7 days' );
		}
	} elseif ( $next <= $now ) {
		$next = $next->modify( '+1 day' );
	}

	wp_schedule_single_event( $next->getTimestamp(), ARTICLE_AUTOMATION_BRIDGE_CRON_HOOK );
}

function article_automation_bridge_clear_generation_schedule() {
	wp_clear_scheduled_hook( ARTICLE_AUTOMATION_BRIDGE_CRON_HOOK );
}

function article_automation_bridge_run_scheduled_generation() {
	try {
		article_automation_bridge_generate_batch();
	} catch ( Exception $exception ) {
		set_transient( 'article_automation_bridge_last_error', sanitize_text_field( $exception->getMessage() ), HOUR_IN_SECONDS );
		error_log( 'Article Automation Bridge: ' . $exception->getMessage() );
	} finally {
		article_automation_bridge_schedule_generation();
	}
}
add_action( ARTICLE_AUTOMATION_BRIDGE_CRON_HOOK, 'article_automation_bridge_run_scheduled_generation' );

function article_automation_bridge_handle_generate_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Acesso não autorizado.', 'article-automation-bridge' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'article_automation_bridge_generate_now' );

	try {
		article_automation_bridge_generate_batch();
		$notice = 'generated';
	} catch ( Exception $exception ) {
		set_transient( 'article_automation_bridge_last_error', sanitize_text_field( $exception->getMessage() ), HOUR_IN_SECONDS );
		error_log( 'Article Automation Bridge: ' . $exception->getMessage() );
		$notice = 'error';
	}

	wp_safe_redirect( add_query_arg( 'article_automation_notice', $notice, admin_url( 'options-general.php?page=article-automation-bridge' ) ) );
	exit;
}
add_action( 'admin_post_article_automation_bridge_generate_now', 'article_automation_bridge_handle_generate_now' );

function article_automation_bridge_generate_batch() {
	if ( get_transient( 'article_automation_bridge_generation_lock' ) ) {
		throw new RuntimeException( 'Já existe uma geração em andamento.' );
	}
	set_transient( 'article_automation_bridge_generation_lock', 1, 30 * MINUTE_IN_SECONDS );

	try {
		$settings = article_automation_bridge_get_settings();
		$api_key  = trim( (string) $settings['api_key'] );
		if ( '' === trim( $settings['model'] ) || '' === trim( $settings['site_name'] ) || '' === trim( $settings['theme'] ) || '' === trim( $settings['audience'] ) || '' === trim( $settings['language'] ) || '' === trim( $settings['tone'] ) ) {
			throw new RuntimeException( 'Configure o modelo, site, tema, público, idioma e tom no painel.' );
		}

		$pending = article_automation_bridge_pending_count();
		$allowed = (int) $settings['max_pending'] - $pending;
		if ( $allowed < 1 ) {
			throw new RuntimeException( 'O limite de rascunhos pendentes foi atingido; revise os posts antes de gerar mais.' );
		}
		$count  = min( (int) $settings['batch_size'], $allowed );
		$topics = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $settings['topics'] ) ) ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$topic   = ! empty( $topics ) ? $topics[ ( $pending + $index ) % count( $topics ) ] : '';
			$article = article_automation_bridge_request_article( $settings, $topic, $api_key );
			$plain   = wp_strip_all_tags( $article['content'] );
			preg_match_all( '/[\p{L}\p{N}]+/u', $plain, $matches );
			if ( count( $matches[0] ) < (int) $settings['min_words'] ) {
				throw new RuntimeException( 'O artigo gerado ficou abaixo do mínimo de palavras configurado.' );
			}

			$source_id = 'wp-auto-panel:' . gmdate( 'Ymd-His' ) . ':' . $index . ':' . wp_generate_password( 8, false, false );
			$author_id = defined( 'ARTICLE_AUTOMATION_BRIDGE_AUTHOR_ID' ) ? absint( ARTICLE_AUTOMATION_BRIDGE_AUTHOR_ID ) : 0;
			if ( ! $author_id || ! get_user_by( 'id', $author_id ) ) {
				$administrators = get_users(
					array(
						'role'   => 'administrator',
						'number' => 1,
						'fields' => 'ID',
					)
				);
				$author_id = ! empty( $administrators ) ? absint( $administrators[0] ) : get_current_user_id();
			}
			$post_id   = wp_insert_post(
				wp_slash(
					array(
						'post_type'     => 'post',
						'post_status'  => 'draft',
						'post_author'  => $author_id,
						'post_title'    => $article['title'],
						'post_content'  => wp_kses_post( $article['content'] ),
						'post_excerpt'  => sanitize_textarea_field( $article['excerpt'] ),
						'post_category' => array( (int) $settings['category_id'] ),
					)
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				throw new RuntimeException( 'O WordPress não conseguiu salvar o rascunho: ' . $post_id->get_error_message() );
			}
			add_post_meta( $post_id, '_article_automation_source_id', $source_id, true );
		}
		delete_transient( 'article_automation_bridge_last_error' );
	} finally {
		delete_transient( 'article_automation_bridge_generation_lock' );
	}
}

function article_automation_bridge_request_article( $settings, $topic, $api_key ) {
	$provider         = isset( $settings['provider'] ) ? $settings['provider'] : 'groq';
	$provider_options = article_automation_bridge_provider_options();
	if ( ! isset( $provider_options[ $provider ] ) ) {
		throw new RuntimeException( 'O provedor de IA configurado não é válido.' );
	}
	$provider_label = $provider_options[ $provider ];
	$model          = trim( (string) $settings['model'] );
	if ( '' === $model ) {
		throw new RuntimeException( 'Configure o modelo do provedor ' . $provider_label . ' no painel.' );
	}
	if ( '' === trim( $api_key ) && 'ollama' !== $provider ) {
		throw new RuntimeException( 'Configure uma chave de API para o provedor ' . $provider_label . ' no painel.' );
	}

	$rules = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $settings['rules'] ) ) );
	$topic_instruction = $topic
		? 'Escreva um artigo sobre: "' . $topic . '".'
		: 'Escolha um tema útil e específico dentro de "' . $settings['theme'] . '".';
	$prompt = sprintf(
		"Crie um artigo original para o site \"%1\$s\".\n\nTema/nicho: %2\$s\nIdioma: %3\$s\nPúblico: %4\$s\nTom: %5\$s\nMínimo de palavras no corpo: %6\$d\n\n%7\$s\n\nRegras editoriais:\n%8\$s\n\nRetorne somente um objeto JSON válido com as chaves title, excerpt e content. content deve ser HTML semântico, sem H1, scripts, estilos, iframes ou Markdown. Não invente fontes ou fatos verificáveis.",
		$settings['site_name'],
		$settings['theme'],
		$settings['language'],
		$settings['audience'],
		$settings['tone'],
		(int) $settings['min_words'],
		$topic_instruction,
		implode( "\n", $rules )
	);

	$instructions = 'Você é um redator editorial. Siga a configuração do site e retorne apenas o JSON solicitado.';
	$headers      = array( 'Content-Type' => 'application/json' );
	$request_body = array();

	switch ( $provider ) {
		case 'groq':
			$url = 'https://api.groq.com/openai/v1/responses';
			$headers['Authorization'] = 'Bearer ' . $api_key;
			$request_body = array(
				'model'             => $model,
				'instructions'      => $instructions,
				'input'             => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'max_output_tokens' => 4000,
				'store'             => false,
			);
			break;
		case 'openai':
			$url = 'https://api.openai.com/v1/responses';
			$headers['Authorization'] = 'Bearer ' . $api_key;
			$request_body = array(
				'model'             => $model,
				'instructions'      => $instructions,
				'input'             => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'max_output_tokens' => 4000,
				'store'             => false,
			);
			break;
		case 'anthropic':
			$url = 'https://api.anthropic.com/v1/messages';
			$headers['x-api-key']         = $api_key;
			$headers['anthropic-version'] = '2023-06-01';
			$request_body = array(
				'model'      => $model,
				'max_tokens' => 4000,
				'system'     => $instructions,
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			);
			break;
		case 'google':
			$url = add_query_arg(
				'key',
				$api_key,
				'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent'
			);
			$request_body = array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $instructions ) ) ),
				'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
				'generationConfig'  => array( 'maxOutputTokens' => 4000 ),
			);
			break;
		case 'openrouter':
			$url = 'https://openrouter.ai/api/v1/chat/completions';
			$headers['Authorization'] = 'Bearer ' . $api_key;
			$request_body = array(
				'model'      => $model,
				'max_tokens' => 4000,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $instructions ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
			);
			break;
		case 'ollama':
			$base_url = ! empty( $settings['base_url'] ) ? untrailingslashit( $settings['base_url'] ) : 'http://localhost:11434/v1';
			$url      = $base_url . '/chat/completions';
			$request_body = array(
				'model'      => $model,
				'max_tokens' => 4000,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $instructions ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
			);
			break;
		case 'custom':
			if ( empty( $settings['base_url'] ) ) {
				throw new RuntimeException( 'Configure a URL base para o provedor compatível com Responses API.' );
			}
			$url = untrailingslashit( $settings['base_url'] ) . '/responses';
			$headers['Authorization'] = 'Bearer ' . $api_key;
			$request_body = array(
				'model'             => $model,
				'instructions'      => $instructions,
				'input'             => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'max_output_tokens' => 4000,
				'store'             => false,
			);
			break;
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 180,
			'headers' => $headers,
			'body'    => wp_json_encode( $request_body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		$error = $response->get_error_message();
		if ( '' !== $api_key ) {
			$error = str_replace( $api_key, '[redacted]', $error );
		}
		throw new RuntimeException( 'Falha ao conectar ao provedor ' . $provider_label . ': ' . $error );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );
	$data   = json_decode( $body, true );
	if ( $status < 200 || $status >= 300 ) {
		$error = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['error'] ) ? wp_json_encode( $data['error'] ) : ( isset( $data['message'] ) ? $data['message'] : substr( $body, 0, 500 ) ) );
		if ( '' !== $api_key ) {
			$error = str_replace( $api_key, '[redacted]', (string) $error );
		}
		$code = isset( $data['error']['code'] ) ? ' (' . sanitize_text_field( $data['error']['code'] ) . ')' : '';
		throw new RuntimeException( sprintf( 'O provedor %1$s respondeu HTTP %2$d: %3$s%4$s', $provider_label, $status, sanitize_text_field( (string) $error ), $code ) );
	}

	$text = article_automation_bridge_extract_response_text( $provider, $data );

	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	if ( false === $start || false === $end || $end <= $start ) {
		throw new RuntimeException( 'O provedor ' . $provider_label . ' não retornou o JSON esperado para o artigo.' );
	}
	$article = json_decode( substr( $text, $start, $end - $start + 1 ), true );
	if (
		! is_array( $article ) ||
		! isset( $article['title'], $article['excerpt'], $article['content'] ) ||
		! is_string( $article['title'] ) ||
		! is_string( $article['excerpt'] ) ||
		! is_string( $article['content'] ) ||
		'' === trim( $article['title'] ) ||
		'' === trim( $article['content'] )
	) {
		throw new RuntimeException( 'A resposta de ' . $provider_label . ' precisa conter título, resumo e conteúdo.' );
	}

	return array(
		'title'   => sanitize_text_field( $article['title'] ),
		'excerpt' => sanitize_textarea_field( $article['excerpt'] ),
		'content' => wp_kses_post( $article['content'] ),
	);
}

function article_automation_bridge_extract_response_text( $provider, $data ) {
	$text = '';
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'O provedor retornou uma resposta vazia ou inválida.' );
	}

	if ( in_array( $provider, array( 'groq', 'openai', 'custom' ), true ) && isset( $data['output'] ) && is_array( $data['output'] ) ) {
		foreach ( $data['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $content ) {
				if ( isset( $content['text'] ) && ( ! isset( $content['type'] ) || in_array( $content['type'], array( 'output_text', 'text' ), true ) ) ) {
					$text .= $content['text'];
				}
			}
		}
	} elseif ( 'anthropic' === $provider && isset( $data['content'] ) && is_array( $data['content'] ) ) {
		foreach ( $data['content'] as $content ) {
			if ( isset( $content['type'], $content['text'] ) && 'text' === $content['type'] ) {
				$text .= $content['text'];
			}
		}
	} elseif ( 'google' === $provider && isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}
	} elseif ( isset( $data['choices'][0]['message']['content'] ) ) {
		$content = $data['choices'][0]['message']['content'];
		if ( is_string( $content ) ) {
			$text = $content;
		} elseif ( is_array( $content ) ) {
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
	}

	if ( '' === trim( $text ) ) {
		throw new RuntimeException( 'O provedor de IA retornou uma resposta vazia ou em formato não reconhecido.' );
	}
	return $text;
}
