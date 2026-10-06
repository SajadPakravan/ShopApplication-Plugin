<?php

namespace AppAPI\Admin;

use AppAPI\Config;
use AppAPI\Support\Cache;
use AppAPI\Support\Request;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private $page_hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function menu(): void {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Application API', 'application-api' ),
			__( 'Application API', 'application-api' ),
			'manage_woocommerce',
			'application-api',
			array( $this, 'render' )
		);
	}

	public function settings(): void {
		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_CONFIG,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_home_configuration' ),
			)
		);

		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_CACHE,
			array(
				'type'              => 'integer',
				'default'           => Config::DEFAULT_HOME_CACHE,
				'sanitize_callback' => static function ( $value ) {
					return min( DAY_IN_SECONDS, max( 0, absint( $value ) ) );
				},
			)
		);

		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_ENDPOINT,
			array(
				'type'              => 'string',
				'default'           => 'home',
				'sanitize_callback' => array( $this, 'sanitize_home_endpoint' ),
			)
		);

		register_setting(
			'app_api_shop_settings',
			Config::OPTION_PRODUCTS_ENDPOINT,
			array(
				'type'              => 'string',
				'default'           => 'products',
				'sanitize_callback' => array( $this, 'sanitize_products_endpoint' ),
			)
		);

		register_setting(
			'app_api_product_settings',
			Config::OPTION_PRODUCT_ENDPOINT,
			array(
				'type'              => 'string',
				'default'           => 'products',
				'sanitize_callback' => array( $this, 'sanitize_product_endpoint' ),
			)
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->page_hook && 'woocommerce_page_application-api' !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'app-api-admin', APP_API_URL . 'assets/admin.css', array(), APP_API_VERSION );
		wp_enqueue_script( 'app-api-admin', APP_API_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), APP_API_VERSION, true );
		wp_localize_script(
			'app-api-admin',
			'appApiAdmin',
			array(
				'optionName'       => Config::OPTION_HOME_CONFIG,
				'mediaTitle'       => 'انتخاب تصویر',
				'mediaButton'      => 'استفاده از این تصویر',
				'emptyImage'       => 'هنوز تصویری انتخاب نشده است',
				'deleteSection'    => 'این بخش و تمام تنظیمات آن حذف شود؟',
				'deleteItem'       => 'این آیتم حذف شود؟',
				'sectionTypeNames' => Config::addable_section_types(),
				'defaultLayouts'   => array(
					'banner'     => Config::default_layout_for_type( 'banner' ),
					'products'   => Config::default_layout_for_type( 'products' ),
					'category' => Config::default_layout_for_type( 'category' ),
					'brand'    => Config::default_layout_for_type( 'brand' ),
					'menu'       => Config::default_layout_for_type( 'menu' ),
				),
			)
		);
	}

	public function sanitize_home_configuration( $input ): array {
		$input          = is_array( $input ) ? wp_unslash( $input ) : array();
		$raw            = isset( $input['sections'] ) && is_array( $input['sections'] ) ? $input['sections'] : array();
		$current_config = Config::home_configuration();
		$result     = array( 'order' => array(), 'sections' => array() );
		$used_ids   = array();
		$valid_types = Config::allowed_section_types();

		foreach ( $raw as $raw_key => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$key = sanitize_key( (string) $raw_key );
			if ( ! $key ) {
				$key = 'section_' . ( count( $result['sections'] ) + 1 );
			}
			while ( isset( $result['sections'][ $key ] ) ) {
				$key .= '_2';
			}

			$submitted_type = sanitize_key( (string) ( $section['type'] ?? '' ) );
			$type = isset( $current_config['sections'][ $key ]['type'] )
				? sanitize_key( (string) $current_config['sections'][ $key ]['type'] )
				: $submitted_type;
			if ( ! in_array( $type, $valid_types, true ) ) {
				continue;
			}

			$id = $this->sanitize_api_id( (string) ( $section['id'] ?? '' ), $key );
			$original_id = $id;
			$suffix      = 2;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $original_id . '_' . $suffix;
				++$suffix;
			}
			if ( $id !== $original_id ) {
				add_settings_error( 'application-api', 'duplicate-section-id-' . $key, 'شناسه تکراری «' . esc_html( $original_id ) . '» به «' . esc_html( $id ) . '» تغییر کرد.', 'warning' );
			}
			$used_ids[ $id ] = true;

			$layout         = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
			$default_layout = Config::default_layout_for_type( $type );
			$direction      = in_array( $layout['direction'] ?? '', array( 'horizontal', 'vertical' ), true ) ? $layout['direction'] : $default_layout['direction'];

			$clean = array(
				'id'       => $id,
				'type'     => $type,
				'enabled'  => ! empty( $section['enabled'] ),
				'title'    => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
				'subtitle' => sanitize_text_field( (string) ( $section['subtitle'] ?? '' ) ),
				'layout'   => array(
					'component' => $this->sanitize_component( (string) ( $layout['component'] ?? $default_layout['component'] ), $default_layout['component'] ),
					'direction' => $direction,
					'rows'      => $this->positive_int( $layout['rows'] ?? 1, 12 ),
					'columns'   => $this->positive_int( $layout['columns'] ?? 1, 12 ),
				),
			);

			if ( 'banner' === $type || 'menu' === $type ) {
				$clean['data'] = $this->sanitize_items( $section['data'] ?? array(), $type );
			}

			if ( 'products' === $type ) {
				$per_page = absint( $section['per_page'] ?? 10 );
				$clean['per_page']       = min( 50, max( 1, $per_page ?: 10 ) );
				$clean['category']       = Request::ids( $section['category'] ?? array() );
				$clean['brand']          = Request::ids( $section['brand'] ?? array() );
				$clean['on_sale']        = ! empty( $section['on_sale'] );
				$clean['view_all_title'] = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
			}

			if ( 'category' === $type || 'brand' === $type ) {
				$clean['include'] = Request::ids( $section['include'] ?? array() );
			}

			$result['sections'][ $key ] = $clean;
		}

		$order_value = $input['order'] ?? array();
		$order       = is_array( $order_value ) ? $order_value : explode( ',', (string) $order_value );
		foreach ( $order as $key ) {
			$key = sanitize_key( (string) $key );
			if ( isset( $result['sections'][ $key ] ) && ! in_array( $key, $result['order'], true ) ) {
				$result['order'][] = $key;
			}
		}
		foreach ( array_keys( $result['sections'] ) as $key ) {
			if ( ! in_array( $key, $result['order'], true ) ) {
				$result['order'][] = $key;
			}
		}

		Cache::bump_home_version();
		update_option( Config::OPTION_HOME_PRESET_VERSION, APP_API_VERSION, false );

		return $result;
	}

	private function sanitize_items( $items, string $section_type ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$result   = array();
		$used_ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title         = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$subtitle      = sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) );
			$attachment_id = absint( $item['attachment_id'] ?? 0 );
			$image         = esc_url_raw( (string) ( $item['image'] ?? '' ) );

			$clean = array(
				'title'         => $title,
				'subtitle'      => $subtitle,
				'attachment_id' => $attachment_id,
				'image'         => $image,
			);

			if ( 'menu' === $section_type ) {
				$fallback = 'menu_' . ( count( $result ) + 1 );
				$id       = $this->sanitize_api_id( (string) ( $item['id'] ?? '' ), $fallback );
				$base_id  = $id;
				$suffix   = 2;
				while ( isset( $used_ids[ $id ] ) ) {
					$id = $base_id . '_' . $suffix;
					++$suffix;
				}
				$used_ids[ $id ] = true;

				$action_type  = sanitize_key( (string) ( $item['action_type'] ?? ( $item['action']['type'] ?? '' ) ) );
				$action_value = sanitize_text_field( (string) ( $item['action_value'] ?? '' ) );
				if ( ! $action_value && ! empty( $item['action'] ) && is_array( $item['action'] ) ) {
					$action_value = isset( $item['action']['id'] ) ? (string) absint( $item['action']['id'] ) : (string) ( $item['action']['url'] ?? ( $item['action']['name'] ?? '' ) );
				}

				$clean = array_merge(
					array( 'id' => $id ),
					$clean,
					array( 'action' => $this->sanitize_action( $action_type, $action_value ) )
				);
			}

			$result[] = $clean;
		}

		return $result;
	}

	private function sanitize_action( string $type, string $value ): array {
		$type = sanitize_key( $type );
		if ( ! $type || 'none' === $type ) {
			return array();
		}

		$action = array( 'type' => $type );
		if ( in_array( $type, array( 'category', 'brand', 'tag', 'product' ), true ) ) {
			if ( is_numeric( $value ) ) {
				$action['id'] = absint( $value );
			} elseif ( '' !== trim( $value ) ) {
				$action['name'] = sanitize_text_field( $value );
			}
		} elseif ( 'url' === $type ) {
			$action['url'] = esc_url_raw( $value );
		}

		return $action;
	}


	private function sanitize_component( string $component, string $fallback ): string {
		$component = trim( $component );
		$component = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $component );
		$component = trim( (string) $component, '_-' );
		return $component ?: $fallback;
	}

	private function sanitize_api_id( string $id, string $fallback ): string {
		$id = strtolower( trim( $id ) );
		$id = preg_replace( '/[^a-z0-9_-]+/', '_', $id );
		$id = trim( (string) $id, '_-' );
		return $id ?: sanitize_key( $fallback );
	}

	private function positive_int( $value, int $maximum ): int {
		$value = absint( $value );
		return min( $maximum, max( 1, $value ?: 1 ) );
	}

	public function sanitize_home_endpoint( $value ): string {
		$current = Config::home_endpoint();
		$slug    = Config::endpoint_slug( $value, 'home' );
		if ( $slug === Config::products_endpoint() ) {
			add_settings_error( 'application-api', 'home-endpoint-conflict', 'آدرس API خانه نباید با آدرس API فهرست محصولات یکسان باشد.', 'error' );
			return $current;
		}
		return $slug;
	}

	public function sanitize_products_endpoint( $value ): string {
		$current = Config::products_endpoint();
		$slug    = Config::endpoint_slug( $value, 'products' );
		if ( $slug === Config::home_endpoint() ) {
			add_settings_error( 'application-api', 'products-endpoint-conflict', 'آدرس API فهرست محصولات نباید با آدرس API خانه یکسان باشد.', 'error' );
			return $current;
		}
		return $slug;
	}

	public function sanitize_product_endpoint( $value ): string {
		return Config::endpoint_slug( $value, 'products' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$config = Config::home_configuration();
		$ttl    = (int) get_option( Config::OPTION_HOME_CACHE, Config::DEFAULT_HOME_CACHE );
		?>
		<div class="wrap app-api-admin-wrap" dir="rtl">
			<div class="app-api-header">
				<div>
					<h1>Application API</h1>
					<p>صفحه‌ساز بصری API اپلیکیشن</p>
				</div>
			</div>

			<?php settings_errors( 'application-api' ); ?>

			<nav class="app-api-tabs" aria-label="صفحات API">
				<button type="button" class="app-api-tab is-active" data-tab="home">خانه</button>
				<button type="button" class="app-api-tab" data-tab="shop">فروشگاه</button>
				<button type="button" class="app-api-tab" data-tab="categories">دسته‌بندی‌ها</button>
				<button type="button" class="app-api-tab" data-tab="product">محصول</button>
				<button type="button" class="app-api-tab" data-tab="account">حساب کاربری</button>
			</nav>

			<section class="app-api-tab-panel is-active" data-panel="home">
				<form method="post" action="options.php" id="app-api-settings-form">
					<?php settings_fields( 'app_api_settings' ); ?>
					<?php $this->render_endpoint_settings( 'خانه', Config::OPTION_HOME_ENDPOINT, Config::home_endpoint(), '' ); ?>
					<?php $this->render_home_order( $config ); ?>

					<div class="app-api-section-heading">
						<div><h2>تنظیمات بخش‌های صفحه خانه</h2><p>هر کارت یک بخش مستقل از JSON خانه است. ID، Component، محتوا و چیدمان هر بخش را مدیریت می‌کنی؛ نوع بخش پس از ساخت ثابت می‌ماند.</p></div>
					</div>

					<div class="app-api-section-cards" id="app-api-section-cards">
						<?php foreach ( $config['order'] as $key ) : ?>
							<?php if ( isset( $config['sections'][ $key ] ) ) : ?>
								<?php $this->render_section_card( $key, $config['sections'][ $key ] ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>

					<div class="app-api-card app-api-cache-card">
						<div><h3>کش API خانه</h3><p>ذخیره تنظیمات، نسخه کش را نوسازی می‌کند تا تغییرات جدید در پاسخ API دیده شوند.</p></div>
						<label>مدت کش (ثانیه)
							<input name="<?php echo esc_attr( Config::OPTION_HOME_CACHE ); ?>" type="number" min="0" max="86400" value="<?php echo esc_attr( $ttl ); ?>">
						</label>
					</div>

					<div class="app-api-save-bar">
						<span>ترتیب بخش‌های فعال، دقیقاً ترتیب آرایه <code>sections</code> در API خانه خواهد بود.</span>
						<?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>

			<section class="app-api-tab-panel" data-panel="shop">
				<form method="post" action="options.php" class="app-api-endpoint-form">
					<?php settings_fields( 'app_api_shop_settings' ); ?>
					<?php $this->render_endpoint_settings( 'فهرست محصولات', Config::OPTION_PRODUCTS_ENDPOINT, Config::products_endpoint(), '' ); ?>
					<?php submit_button( 'ذخیره آدرس API' ); ?>
				</form>
			</section>

			<section class="app-api-tab-panel" data-panel="product">
				<form method="post" action="options.php" class="app-api-endpoint-form">
					<?php settings_fields( 'app_api_product_settings' ); ?>
					<?php $this->render_endpoint_settings( 'جزئیات محصول', Config::OPTION_PRODUCT_ENDPOINT, Config::product_endpoint(), '/{id}' ); ?>
					<?php submit_button( 'ذخیره آدرس API' ); ?>
				</form>
			</section>

			<?php foreach ( array( 'categories' => 'دسته‌بندی‌ها', 'account' => 'حساب کاربری' ) as $key => $label ) : ?>
				<section class="app-api-tab-panel" data-panel="<?php echo esc_attr( $key ); ?>">
					<div class="app-api-empty-state"><span class="dashicons dashicons-admin-tools"></span><h2>تنظیمات API <?php echo esc_html( $label ); ?></h2><p>پس از ایجاد این API، تنظیمات اختصاصی آن در همین تب قرار می‌گیرد.</p></div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php $this->render_section_template(); ?>
		<?php
	}

	private function render_endpoint_settings( string $label, string $option_name, string $endpoint, string $suffix ): void {
		$base_url = rest_url( Config::REST_NAMESPACE . '/' );
		$full_url = $base_url . $endpoint . $suffix;
		?>
		<div class="app-api-card app-api-endpoint-card">
			<div class="app-api-card-title">
				<div><h2>آدرس API <?php echo esc_html( $label ); ?></h2><p>نام endpoint را با حروف انگلیسی، عدد، خط تیره یا زیرخط تعیین کن.</p></div>
			</div>
			<div class="app-api-field-grid two-columns">
				<label><span>نام endpoint</span><input type="text" dir="ltr" class="app-api-endpoint-slug" data-endpoint-suffix="<?php echo esc_attr( $suffix ); ?>" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $endpoint ); ?>"></label>
				<label><span>آدرس کامل API</span><input type="text" dir="ltr" class="app-api-endpoint-url" data-endpoint-base="<?php echo esc_attr( $base_url ); ?>" value="<?php echo esc_attr( $full_url ); ?>" readonly></label>
			</div>
		</div>
		<?php
	}

	private function render_home_order( array $config ): void {
		?>
		<div class="app-api-card app-api-order-card">
			<div class="app-api-card-title">
				<div><h2>فعال‌سازی و ترتیب نمایش</h2><p>بخش‌های فعال را جابه‌جا کن؛ بخش غیرفعال از API و فهرست ترتیب حذف می‌شود، اما تنظیماتش حفظ می‌گردد.</p></div>
				<span class="app-api-pill">Drag & Drop</span>
			</div>

			<div class="app-api-builder-toolbar">
				<div>
					<strong>افزودن بخش جدید</strong>
					<span>نوع بخش را انتخاب کن؛ نوع پس از ساخت ثابت است و سایر تنظیمات قابل تغییر هستند.</span>
				</div>
				<div class="app-api-add-section-controls">
					<select id="app-api-new-section-type">
						<?php foreach ( Config::addable_section_types() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-primary" id="app-api-add-section"><span class="dashicons dashicons-plus-alt2"></span> افزودن بخش</button>
				</div>
			</div>

			<div class="app-api-toggle-grid" id="app-api-section-toggles">
				<?php foreach ( $config['order'] as $key ) : ?>
					<?php
					$section = $config['sections'][ $key ];
					$label   = $this->section_label( $section );
					?>
					<label class="app-api-toggle" data-toggle-section="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( $label ); ?>" data-type="<?php echo esc_attr( $section['type'] ); ?>">
						<input type="checkbox" class="app-api-section-enabled" name="<?php echo esc_attr( Config::OPTION_HOME_CONFIG ); ?>[sections][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $section['enabled'] ) ); ?>>
						<span class="app-api-switch"></span>
						<span class="app-api-toggle-label"><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<input type="hidden" id="app-api-section-order" name="<?php echo esc_attr( Config::OPTION_HOME_CONFIG ); ?>[order]" value="<?php echo esc_attr( implode( ',', $config['order'] ) ); ?>">
			<ul class="app-api-sortable" id="app-api-active-sections">
				<?php foreach ( $config['order'] as $key ) : ?>
					<?php $section = $config['sections'][ $key ]; ?>
					<?php if ( ! empty( $section['enabled'] ) ) : ?>
						<?php $this->render_order_item( $key, $this->section_label( $section ), $section['type'] ); ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function render_order_item( string $key, string $label, string $type ): void {
		?>
		<li class="app-api-order-item" data-section-id="<?php echo esc_attr( $key ); ?>">
			<span class="dashicons dashicons-move app-api-drag-handle" aria-hidden="true"></span>
			<div class="app-api-order-name"><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( Config::section_type_label( $type ) ); ?></small></div>
			<div class="app-api-order-actions">
				<button type="button" class="button app-api-move-up" aria-label="انتقال به بالا"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
				<button type="button" class="button app-api-move-down" aria-label="انتقال به پایین"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
			</div>
		</li>
		<?php
	}

	private function render_section_card( string $key, array $section ): void {
		$type   = sanitize_key( (string) ( $section['type'] ?? 'products' ) );
		$label  = $this->section_label( $section );
		$name   = Config::OPTION_HOME_CONFIG . '[sections][' . $key . ']';
		$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : Config::default_layout_for_type( $type );
		?>
		<details class="app-api-section-card <?php echo empty( $section['enabled'] ) ? 'is-disabled' : ''; ?>" data-section-card="<?php echo esc_attr( $key ); ?>" data-section-type="<?php echo esc_attr( $type ); ?>">
			<summary>
				<div><strong class="app-api-card-label"><?php echo esc_html( $label ); ?></strong><small><code class="app-api-card-id"><?php echo esc_html( $section['id'] ); ?></code> · <span class="app-api-card-type"><?php echo esc_html( Config::section_type_label( $type ) ); ?></span></small></div>
				<div class="app-api-summary-actions"><span class="app-api-status"><?php echo empty( $section['enabled'] ) ? 'غیرفعال' : 'فعال'; ?></span><button type="button" class="button-link-delete app-api-delete-section">حذف بخش</button></div>
			</summary>
			<div class="app-api-section-body">
				<div class="app-api-field-grid four-columns">
					<label><span>ID دلخواه بخش</span><input type="text" dir="ltr" class="app-api-section-id-input" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $section['id'] ); ?>" placeholder="مثلاً amazing_offers"><small>فقط حروف انگلیسی، عدد، خط تیره و زیرخط</small></label>
					<label><span>نوع بخش</span><input type="text" dir="ltr" class="app-api-section-type-display" value="<?php echo esc_attr( $type ); ?>" readonly><input type="hidden" class="app-api-section-type-select" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $type ); ?>"><small>نوع این بخش ثابت است.</small></label>
					<label><span>عنوان بخش</span><input type="text" class="app-api-section-title-input" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $section['title'] ?? '' ); ?>" placeholder="عنوان قابل نمایش"></label>
					<label><span>زیرعنوان بخش</span><input type="text" name="<?php echo esc_attr( $name ); ?>[subtitle]" value="<?php echo esc_attr( $section['subtitle'] ?? '' ); ?>" placeholder="متن کوتاه زیر عنوان"></label>
				</div>

				<div class="app-api-subcard">
					<div class="app-api-subcard-heading"><div><h4>چیدمان خروجی</h4><p>اپلیکیشن می‌تواند از Component و تعداد سطر / ستون برای انتخاب ویجت و نحوه چیدن آیتم‌ها استفاده کند.</p></div></div>
					<div class="app-api-field-grid four-columns">
						<label><span>Component</span><input type="text" dir="ltr" class="app-api-component-input" name="<?php echo esc_attr( $name ); ?>[layout][component]" value="<?php echo esc_attr( $layout['component'] ?? '' ); ?>"></label>
						<label><span>جهت نمایش</span><select name="<?php echo esc_attr( $name ); ?>[layout][direction]"><option value="horizontal" <?php selected( $layout['direction'] ?? '', 'horizontal' ); ?>>افقی</option><option value="vertical" <?php selected( $layout['direction'] ?? '', 'vertical' ); ?>>عمودی</option></select></label>
						<label><span>تعداد سطر</span><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[layout][rows]" value="<?php echo esc_attr( $layout['rows'] ?? 1 ); ?>" placeholder="پیش‌فرض 1"></label>
						<label><span>تعداد ستون</span><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[layout][columns]" value="<?php echo esc_attr( $layout['columns'] ?? 1 ); ?>" placeholder="پیش‌فرض 1"></label>
					</div>
				</div>

				<div class="app-api-type-panel" data-type-panel="products" <?php echo 'products' !== $type ? 'hidden' : ''; ?>><?php $this->render_products_settings( $name, $section ); ?></div>
				<div class="app-api-type-panel" data-type-panel="category" <?php echo 'category' !== $type ? 'hidden' : ''; ?>><?php $this->render_terms_settings( $name, $section, 'category' ); ?></div>
				<div class="app-api-type-panel" data-type-panel="brand" <?php echo 'brand' !== $type ? 'hidden' : ''; ?>><?php $this->render_terms_settings( $name, $section, 'brand' ); ?></div>
				<div class="app-api-type-panel" data-type-panel="banner" <?php echo 'banner' !== $type ? 'hidden' : ''; ?>><?php $this->render_repeater( $key, 'banner', $section['data'] ?? array(), $name ); ?></div>
				<div class="app-api-type-panel" data-type-panel="menu" <?php echo 'menu' !== $type ? 'hidden' : ''; ?>><?php $this->render_repeater( $key, 'menu', $section['data'] ?? array(), $name ); ?></div>
			</div>
		</details>
		<?php
	}

	private function render_products_settings( string $name, array $section ): void {
		?>
		<div class="app-api-subcard">
			<div class="app-api-subcard-heading"><div><h4>منبع و فیلتر محصولات</h4><p>محصولات همیشه از جدیدترین به قدیمی‌ترین هستند. چند دسته یا چند برند در هر فیلد با رابطه «یا» و دسته با برند با رابطه «و» فیلتر می‌شوند.</p></div></div>
			<div class="app-api-field-grid four-columns">
				<label><span>تعداد نمایش (per_page)</span><input type="number" min="1" max="50" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( $section['per_page'] ?? 10 ); ?>" placeholder="پیش‌فرض 10"></label>
				<label><span>آیدی دسته‌بندی‌ها</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[category]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['category'] ?? array() ) ) ); ?>" placeholder="مثلاً 166,350"></label>
				<label><span>آیدی برندها</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[brand]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['brand'] ?? array() ) ) ); ?>" placeholder="مثلاً 313,362"></label>
				<label><span>عنوان اسلاید پایانی</span><input type="text" name="<?php echo esc_attr( $name ); ?>[view_all_title]" value="<?php echo esc_attr( $section['view_all_title'] ?? 'مشاهده همه' ); ?>"></label>
			</div>
			<label class="app-api-check app-api-sale-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on_sale]" value="1" <?php checked( ! empty( $section['on_sale'] ) ); ?>> فقط محصولات تخفیف‌دار نمایش داده شوند</label>
			<p class="description">اگر دسته‌بندی و برند خالی باشند، جدیدترین محصولات کل فروشگاه نمایش داده می‌شوند. فیلترهای همین بخش در <code>view_all.action</code> نیز برمی‌گردند.</p>
		</div>
		<?php
	}

	private function render_terms_settings( string $name, array $section, string $type ): void {
		$is_brand = 'brand' === $type;
		?>
		<div class="app-api-subcard">
			<div class="app-api-subcard-heading"><div><h4><?php echo $is_brand ? 'برندهای انتخابی' : 'دسته‌بندی‌های انتخابی'; ?></h4><p>فقط شناسه‌هایی که وارد می‌کنی و دقیقاً با همان ترتیب در API نمایش داده می‌شوند.</p></div></div>
			<label><span><?php echo $is_brand ? 'آیدی برندها به ترتیب نمایش' : 'آیدی دسته‌بندی‌ها به ترتیب نمایش'; ?></span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[include]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['include'] ?? array() ) ) ); ?>" placeholder="مثلاً 55,166,350"></label>
		</div>
		<?php
	}

	private function render_repeater( string $section_key, string $type, array $items, string $base_name ): void {
		$is_menu = 'menu' === $type;
		?>
		<div class="app-api-subcard app-api-repeater" data-section="<?php echo esc_attr( $section_key ); ?>" data-kind="<?php echo esc_attr( $is_menu ? 'menu' : 'banner' ); ?>">
			<div class="app-api-repeater-header"><div><h4><?php echo $is_menu ? 'آیتم‌های منو' : 'لیست بنرها'; ?></h4><p><?php echo $is_menu ? 'آیکون، عنوان و مقصد هر گزینه منو را مشخص و ترتیبشان را با درگ تغییر بده.' : 'یک یا چند بنر بساز؛ تعداد سطر و ستون از تنظیمات چیدمان همین بخش خوانده می‌شود.'; ?></p></div><button type="button" class="button button-secondary app-api-add-item"><span class="dashicons dashicons-plus-alt2"></span> افزودن آیتم</button></div>
			<div class="app-api-items-sortable">
				<?php foreach ( $items as $index => $item ) : ?>
					<?php $this->render_repeater_item( $type, (string) $index, is_array( $item ) ? $item : array(), $base_name ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_repeater_item( string $type, string $index, array $item, string $base_name ): void {
		$is_menu       = 'menu' === $type;
		$item_name     = $base_name . '[data][' . $index . ']';
		$action        = isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : array();
		$action_type   = sanitize_key( (string) ( $action['type'] ?? 'none' ) );
		$action_value  = isset( $action['id'] ) ? (string) $action['id'] : (string) ( $action['url'] ?? ( $action['name'] ?? '' ) );
		$image         = esc_url( (string) ( $item['image'] ?? '' ) );
		$attachment_id = absint( $item['attachment_id'] ?? 0 );
		?>
		<div class="app-api-repeater-item" data-item-index="<?php echo esc_attr( $index ); ?>">
			<div class="app-api-item-topbar"><span class="dashicons dashicons-move app-api-item-drag"></span><strong><?php echo esc_html( $item['title'] ?? ( $is_menu ? 'گزینه منو' : 'بنر' ) ); ?></strong><button type="button" class="button-link-delete app-api-remove-item">حذف</button></div>
			<div class="app-api-item-content">
				<div class="app-api-media-field">
					<div class="app-api-image-preview <?php echo $image ? 'has-image' : ''; ?>"><?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" alt=""><?php else : ?><span class="dashicons dashicons-format-image"></span><small>تصویری انتخاب نشده</small><?php endif; ?></div>
					<input type="hidden" class="app-api-attachment-id" name="<?php echo esc_attr( $item_name ); ?>[attachment_id]" value="<?php echo esc_attr( $attachment_id ); ?>">
					<input type="hidden" class="app-api-image-url" name="<?php echo esc_attr( $item_name ); ?>[image]" value="<?php echo esc_attr( $image ); ?>">
					<button type="button" class="button app-api-select-image"><?php echo $is_menu ? 'انتخاب آیکون' : 'انتخاب تصویر'; ?></button>
				</div>
				<div class="app-api-item-fields">
					<?php if ( $is_menu ) : ?>
						<div class="app-api-field-grid three-columns"><label><span>ID آیتم</span><input type="text" dir="ltr" name="<?php echo esc_attr( $item_name ); ?>[id]" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>" placeholder="اختیاری"></label><label><span>عنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[title]" value="<?php echo esc_attr( $item['title'] ?? '' ); ?>"></label><label><span>زیرعنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[subtitle]" value="<?php echo esc_attr( $item['subtitle'] ?? '' ); ?>"></label></div>
						<div class="app-api-field-grid two-columns"><label><span>نوع مقصد</span><select name="<?php echo esc_attr( $item_name ); ?>[action_type]" class="app-api-action-type"><?php $this->render_action_options( $action_type ); ?></select></label><label><span>آیدی یا لینک مقصد</span><input type="text" dir="ltr" name="<?php echo esc_attr( $item_name ); ?>[action_value]" value="<?php echo esc_attr( $action_value ); ?>" placeholder="مثلاً 350 یا https://..."></label></div>
					<?php else : ?>
						<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[title]" value="<?php echo esc_attr( $item['title'] ?? '' ); ?>"></label><label><span>زیرعنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[subtitle]" value="<?php echo esc_attr( $item['subtitle'] ?? '' ); ?>"></label></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_action_options( string $selected ): void {
		$options = array(
			'none'                => 'بدون عملیات',
			'category'            => 'دسته‌بندی محصول',
			'product'             => 'محصول',
			'products'            => 'صفحه محصولات',
			'brand'               => 'برند',
			'tag'                 => 'برچسب',
			'url'                 => 'لینک دلخواه',
			'app_download'        => 'دانلود اپلیکیشن',
			'purchase_consulting' => 'مشاوره خرید',
			'goods_order'         => 'سفارش اجناس',
			'assembly_order'      => 'سفارش مونتاژ',
			'repair_order'        => 'سفارش تعمیرات',
			'return_request'      => 'درخواست مرجوعی',
			'store_payment'       => 'پرداخت فروشگاه',
			'survey'              => 'نظرسنجی',
		);
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
	}

	private function section_label( array $section ): string {
		$title = trim( (string) ( $section['title'] ?? '' ) );
		if ( '' !== $title ) {
			return $title;
		}
		$id = trim( (string) ( $section['id'] ?? '' ) );
		return '' !== $id ? $id : Config::section_type_label( (string) ( $section['type'] ?? '' ) );
	}

	private function render_section_template(): void {
		?>
		<script type="text/template" id="app-api-section-template">
			<?php
			$template = Config::new_section_defaults( 'products', '__KEY__' );
			$template['id'] = '__ID__';
			$this->render_section_card( '__KEY__', $template );
			?>
		</script>
		<?php
	}
}
