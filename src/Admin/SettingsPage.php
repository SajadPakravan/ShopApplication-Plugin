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
				'optionName'  => Config::OPTION_HOME_CONFIG,
				'mediaTitle'  => 'انتخاب تصویر',
				'mediaButton' => 'استفاده از این تصویر',
				'emptyImage'  => 'هنوز تصویری انتخاب نشده است',
			)
		);
	}

	public function sanitize_home_configuration( $input ): array {
		$defaults = Config::home_configuration();
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$raw      = isset( $input['sections'] ) && is_array( $input['sections'] ) ? $input['sections'] : array();
		$result   = array( 'order' => array(), 'sections' => array() );

		foreach ( $defaults['sections'] as $id => $default ) {
			$section = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$type    = sanitize_key( $default['type'] ?? 'custom' );
			$clean   = $default;

			$clean['id']       = $id;
			$clean['type']     = $type;
			$clean['enabled']  = ! empty( $section['enabled'] );
			$clean['title']    = sanitize_text_field( $section['title'] ?? ( $default['title'] ?? '' ) );
			$clean['subtitle'] = sanitize_text_field( $section['subtitle'] ?? ( $default['subtitle'] ?? '' ) );

			$default_layout = Config::default_layout_for_type( $type );
			$layout         = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
			$clean['layout'] = array(
				'component' => sanitize_key( $layout['component'] ?? $default_layout['component'] ),
				'direction' => in_array( $layout['direction'] ?? '', array( 'horizontal', 'vertical' ), true ) ? $layout['direction'] : $default_layout['direction'],
				'columns'   => min( 12, max( 1, absint( $layout['columns'] ?? $default_layout['columns'] ) ) ),
			);

			if ( 'products' === $type ) {
				$clean['source']   = sanitize_key( $default['source'] ?? 'latest' );
				$clean['per_page'] = min( 30, max( 1, absint( $section['per_page'] ?? ( $default['per_page'] ?? 10 ) ) ) );

				if ( 'category' === $clean['source'] ) {
					$clean['category']       = Request::ids( $section['category'] ?? array() );
					$clean['category_names'] = array();
					$clean['category_slugs'] = array();
				}

				if ( isset( $default['view_all'] ) ) {
					$view_all_title   = sanitize_text_field( $section['view_all_title'] ?? ( $default['view_all']['title'] ?? 'مشاهده همه' ) );
					$clean['view_all'] = $default['view_all'];
					$clean['view_all']['title'] = $view_all_title ?: 'مشاهده همه';
				}
			}

			if ( 'categories' === $type || 'brands' === $type ) {
				$config = isset( $default['config'] ) && is_array( $default['config'] ) ? $default['config'] : array();
				$config['include']       = Request::ids( $section['include'] ?? array() );
				$config['include_names'] = array();
				$config['include_slugs'] = array();
				$config['hide_empty']    = ! empty( $section['hide_empty'] );
				$clean['config']         = $config;
			}

			if ( in_array( $type, array( 'banner_slider', 'promo_banners', 'action_menu' ), true ) ) {
				$clean['data'] = $this->sanitize_items( $section['data'] ?? array(), $type );
			}

			$result['sections'][ $id ] = $clean;
		}

		$order_value = $input['order'] ?? array();
		$order       = is_array( $order_value ) ? $order_value : explode( ',', (string) $order_value );
		foreach ( $order as $id ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $result['sections'][ $id ] ) && ! in_array( $id, $result['order'], true ) ) {
				$result['order'][] = $id;
			}
		}
		foreach ( array_keys( $result['sections'] ) as $id ) {
			if ( ! in_array( $id, $result['order'], true ) ) {
				$result['order'][] = $id;
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

		$result = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title         = sanitize_text_field( $item['title'] ?? '' );
			$subtitle      = sanitize_text_field( $item['subtitle'] ?? '' );
			$attachment_id = absint( $item['attachment_id'] ?? 0 );
			$image         = esc_url_raw( $item['image'] ?? '' );
			$action_type   = sanitize_key( $item['action_type'] ?? ( $item['action']['type'] ?? '' ) );
			$action_value  = sanitize_text_field( $item['action_value'] ?? '' );

			if ( ! $action_value && ! empty( $item['action'] ) && is_array( $item['action'] ) ) {
				$action_value = isset( $item['action']['id'] ) ? (string) absint( $item['action']['id'] ) : (string) ( $item['action']['url'] ?? ( $item['action']['name'] ?? '' ) );
			}

			$id = sanitize_key( $item['id'] ?? '' );
			if ( ! $id ) {
				$id_base = sanitize_key( $title );
				$id      = ( $id_base ?: sanitize_key( $section_type ) ?: 'item' ) . '-' . ( count( $result ) + 1 );
			}

			$result[] = array(
				'id'            => $id,
				'title'         => $title,
				'subtitle'      => $subtitle,
				'attachment_id' => $attachment_id,
				'image'         => $image,
				'action'        => $this->sanitize_action( $action_type, $action_value ),
			);
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
					<p>تنظیمات API اپلیکیشن بدون نیاز به نوشتن JSON</p>
				</div>
				<a class="button button-secondary" href="<?php echo esc_url( rest_url( Config::REST_NAMESPACE . '/home' ) ); ?>" target="_blank" rel="noopener">مشاهده API خانه</a>
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
					<?php $this->render_home_order( $config ); ?>

					<div class="app-api-section-heading">
						<div><h2>تنظیمات بخش‌های صفحه خانه</h2><p>هر بخش مستقل است؛ عنوان، زیرعنوان، داده‌ها و تنظیمات نمایشش را جداگانه تغییر بده.</p></div>
					</div>

					<div class="app-api-section-cards">
						<?php
						foreach ( $config['order'] as $id ) {
							if ( isset( $config['sections'][ $id ] ) ) {
								$this->render_section_card( $id, $config['sections'][ $id ] );
							}
						}
						?>
					</div>

					<div class="app-api-card app-api-cache-card">
						<div><h3>کش API خانه</h3><p>برای اعمال فوری تغییرات، ذخیره تنظیمات نسخه کش را نیز نوسازی می‌کند.</p></div>
						<label>مدت کش (ثانیه)
							<input name="<?php echo esc_attr( Config::OPTION_HOME_CACHE ); ?>" type="number" min="0" max="86400" value="<?php echo esc_attr( $ttl ); ?>">
						</label>
					</div>

					<div class="app-api-save-bar">
						<span>ترتیب فعال فعلی مستقیماً ترتیب آرایه <code>sections</code> در API خواهد بود.</span>
						<?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>

			<?php foreach ( array( 'shop' => 'فروشگاه', 'categories' => 'دسته‌بندی‌ها', 'product' => 'محصول', 'account' => 'حساب کاربری' ) as $key => $label ) : ?>
				<section class="app-api-tab-panel" data-panel="<?php echo esc_attr( $key ); ?>">
					<div class="app-api-empty-state"><span class="dashicons dashicons-admin-tools"></span><h2>تنظیمات API <?php echo esc_html( $label ); ?></h2><p>جای این صفحه آماده شده است و تنظیماتش همراه با طراحی API همان صفحه اضافه می‌شود.</p></div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_home_order( array $config ): void {
		$labels = Config::section_labels();
		?>
		<div class="app-api-card app-api-order-card">
			<div class="app-api-card-title">
				<div><h2>فعال‌سازی و ترتیب نمایش</h2><p>بخش‌های فعال را با ماوس جابه‌جا کن یا از دکمه‌های بالا و پایین استفاده کن.</p></div>
				<span class="app-api-pill">Drag & Drop</span>
			</div>

			<div class="app-api-toggle-grid">
				<?php foreach ( $config['order'] as $id ) :
					$section = $config['sections'][ $id ];
					$label   = $labels[ $id ] ?? ( $section['title'] ?: $id );
					?>
					<label class="app-api-toggle" data-toggle-section="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $label ); ?>" data-type="<?php echo esc_attr( $section['type'] ); ?>">
						<input type="checkbox" class="app-api-section-enabled" name="<?php echo esc_attr( Config::OPTION_HOME_CONFIG ); ?>[sections][<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( ! empty( $section['enabled'] ) ); ?>>
						<span class="app-api-switch"></span>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<input type="hidden" id="app-api-section-order" name="<?php echo esc_attr( Config::OPTION_HOME_CONFIG ); ?>[order]" value="<?php echo esc_attr( implode( ',', $config['order'] ) ); ?>">
			<ul class="app-api-sortable" id="app-api-active-sections">
				<?php foreach ( $config['order'] as $id ) :
					$section = $config['sections'][ $id ];
					if ( empty( $section['enabled'] ) ) {
						continue;
					}
					$label = $labels[ $id ] ?? ( $section['title'] ?: $id );
					$this->render_order_item( $id, $label, $section['type'] );
				endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function render_order_item( string $id, string $label, string $type ): void {
		?>
		<li class="app-api-order-item" data-section-id="<?php echo esc_attr( $id ); ?>">
			<span class="dashicons dashicons-move app-api-drag-handle" aria-hidden="true"></span>
			<div class="app-api-order-name"><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $type ); ?></small></div>
			<div class="app-api-order-actions">
				<button type="button" class="button app-api-move-up" aria-label="انتقال به بالا"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
				<button type="button" class="button app-api-move-down" aria-label="انتقال به پایین"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
			</div>
		</li>
		<?php
	}

	private function render_section_card( string $id, array $section ): void {
		$labels = Config::section_labels();
		$type   = sanitize_key( $section['type'] ?? 'custom' );
		$label  = $labels[ $id ] ?? ( $section['title'] ?: $id );
		$name   = Config::OPTION_HOME_CONFIG . '[sections][' . $id . ']';
		$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : Config::default_layout_for_type( $type );
		?>
		<details class="app-api-section-card <?php echo empty( $section['enabled'] ) ? 'is-disabled' : ''; ?>" data-section-card="<?php echo esc_attr( $id ); ?>">
			<summary>
				<div><strong><?php echo esc_html( $label ); ?></strong><small><code><?php echo esc_html( $id ); ?></code> · <?php echo esc_html( $type ); ?></small></div>
				<span class="app-api-status"><?php echo empty( $section['enabled'] ) ? 'غیرفعال' : 'فعال'; ?></span>
			</summary>
			<div class="app-api-section-body">
				<div class="app-api-field-grid two-columns">
					<label><span>عنوان بخش</span><input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $section['title'] ?? '' ); ?>" placeholder="عنوان قابل نمایش در اپلیکیشن"></label>
					<label><span>زیرعنوان بخش</span><input type="text" name="<?php echo esc_attr( $name ); ?>[subtitle]" value="<?php echo esc_attr( $section['subtitle'] ?? '' ); ?>" placeholder="متن کوتاه زیر عنوان"></label>
				</div>

				<div class="app-api-subcard">
					<h4>شناسه و چیدمان خروجی</h4>
					<div class="app-api-field-grid four-columns">
						<label><span>ID بخش</span><input type="text" value="<?php echo esc_attr( $id ); ?>" readonly></label>
						<label><span>Component</span><input type="text" name="<?php echo esc_attr( $name ); ?>[layout][component]" value="<?php echo esc_attr( $layout['component'] ?? '' ); ?>"></label>
						<label><span>جهت نمایش</span><select name="<?php echo esc_attr( $name ); ?>[layout][direction]"><option value="horizontal" <?php selected( $layout['direction'] ?? '', 'horizontal' ); ?>>افقی</option><option value="vertical" <?php selected( $layout['direction'] ?? '', 'vertical' ); ?>>عمودی</option></select></label>
						<label><span>تعداد ستون</span><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[layout][columns]" value="<?php echo esc_attr( $layout['columns'] ?? 1 ); ?>"></label>
					</div>
				</div>

				<?php if ( 'products' === $type ) : ?>
					<div class="app-api-subcard">
						<h4>تنظیمات محصولات</h4>
						<div class="app-api-field-grid three-columns">
							<label><span>تعداد نمایش (per_page)</span><input type="number" min="1" max="30" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( $section['per_page'] ?? 10 ); ?>"></label>
							<?php if ( 'category' === ( $section['source'] ?? '' ) ) : ?>
								<label class="span-two"><span>آیدی دسته‌بندی‌ها</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[category]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['category'] ?? array() ) ) ); ?>" placeholder="مثال: 166 یا 166,350"></label>
							<?php else : ?>
								<div class="app-api-fixed-rule"><span>منبع ثابت</span><strong><?php echo 'on_sale' === ( $section['source'] ?? '' ) ? 'جدیدترین محصولات تخفیف‌دار' : 'جدیدترین محصولات'; ?></strong></div>
							<?php endif; ?>
						</div>
						<?php if ( isset( $section['view_all'] ) ) : ?>
							<label class="app-api-inline-field"><span>عنوان اسلاید پایانی</span><input type="text" name="<?php echo esc_attr( $name ); ?>[view_all_title]" value="<?php echo esc_attr( $section['view_all']['title'] ?? 'مشاهده همه' ); ?>"></label>
						<?php endif; ?>
						<p class="description">در صفحه خانه همیشه صفحه اول و مرتب‌سازی تاریخ نزولی استفاده می‌شود؛ فقط تعداد نمایش و دسته‌بندی ثابت این بخش قابل تنظیم است.</p>
					</div>
				<?php elseif ( 'categories' === $type ) : ?>
					<div class="app-api-subcard">
						<h4>دسته‌بندی‌های انتخابی</h4>
						<label><span>آیدی دسته‌بندی‌ها به ترتیب نمایش</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[include]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['config']['include'] ?? array() ) ) ); ?>" placeholder="مثال: 55,166,350"></label>
						<label class="app-api-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_empty]" value="1" <?php checked( ! empty( $section['config']['hide_empty'] ) ); ?>> دسته‌بندی‌های بدون محصول نمایش داده نشوند</label>
					</div>
				<?php elseif ( 'brands' === $type ) : ?>
					<div class="app-api-subcard">
						<h4>برندهای انتخابی</h4>
						<label><span>آیدی برندها به ترتیب نمایش</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[include]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['config']['include'] ?? array() ) ) ); ?>" placeholder="مثال: 313,362,367"></label>
						<label class="app-api-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_empty]" value="1" <?php checked( ! empty( $section['config']['hide_empty'] ) ); ?>> برندهای بدون محصول نمایش داده نشوند</label>
					</div>
				<?php elseif ( in_array( $type, array( 'banner_slider', 'promo_banners', 'action_menu' ), true ) ) : ?>
					<?php $this->render_repeater( $id, $type, $section['data'] ?? array(), $name ); ?>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private function render_repeater( string $section_id, string $type, array $items, string $base_name ): void {
		$is_menu = 'action_menu' === $type;
		?>
		<div class="app-api-subcard app-api-repeater" data-section="<?php echo esc_attr( $section_id ); ?>" data-kind="<?php echo esc_attr( $is_menu ? 'menu' : 'banner' ); ?>">
			<div class="app-api-repeater-header"><div><h4><?php echo $is_menu ? 'آیتم‌های منو' : 'تصاویر و بنرها'; ?></h4><p><?php echo $is_menu ? 'برای هر گزینه عنوان، زیرعنوان، آیکون و مقصد را تنظیم کن.' : 'برای هر بنر تصویر اصلی، عنوان، زیرعنوان و مقصد را تنظیم کن.'; ?></p></div><button type="button" class="button button-secondary app-api-add-item"><span class="dashicons dashicons-plus-alt2"></span> افزودن آیتم</button></div>
			<div class="app-api-items-sortable">
				<?php foreach ( $items as $index => $item ) :
					$this->render_repeater_item( $section_id, $type, (string) $index, $item, $base_name );
				endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_repeater_item( string $section_id, string $type, string $index, array $item, string $base_name ): void {
		$is_menu       = 'action_menu' === $type;
		$item_name     = $base_name . '[data][' . $index . ']';
		$action        = isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : array();
		$action_type   = sanitize_key( $action['type'] ?? 'none' );
		$action_value  = isset( $action['id'] ) ? (string) $action['id'] : (string) ( $action['url'] ?? ( $action['name'] ?? '' ) );
		$image         = esc_url( $item['image'] ?? '' );
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
					<input type="hidden" name="<?php echo esc_attr( $item_name ); ?>[id]" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
					<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[title]" value="<?php echo esc_attr( $item['title'] ?? '' ); ?>"></label><label><span>زیرعنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[subtitle]" value="<?php echo esc_attr( $item['subtitle'] ?? '' ); ?>"></label></div>
					<div class="app-api-field-grid two-columns"><label><span>نوع مقصد</span><select name="<?php echo esc_attr( $item_name ); ?>[action_type]" class="app-api-action-type"><?php $this->render_action_options( $action_type ); ?></select></label><label><span>آیدی یا لینک مقصد</span><input type="text" dir="ltr" name="<?php echo esc_attr( $item_name ); ?>[action_value]" value="<?php echo esc_attr( $action_value ); ?>" placeholder="مثلاً 350 یا https://..."></label></div>
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
}
