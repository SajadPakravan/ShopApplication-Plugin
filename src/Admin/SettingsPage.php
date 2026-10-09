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
		$this->page_hook = add_menu_page(
			__( 'Application API', 'application-api' ),
			__( 'Application API', 'application-api' ),
			'manage_options',
			'application-api',
			array( $this, 'render' ),
			'dashicons-rest-api',
			56
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
			Config::OPTION_HOME_ENDPOINT,
			array(
				'type'              => 'string',
				'default'           => 'home',
				'sanitize_callback' => array( $this, 'sanitize_home_endpoint' ),
			)
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->page_hook && 'toplevel_page_application-api' !== $hook ) {
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
					'image'    => Config::default_layout_for_type( 'image' ),
					'products' => Config::default_layout_for_type( 'products' ),
					'category' => Config::default_layout_for_type( 'category' ),
					'brand'    => Config::default_layout_for_type( 'brand' ),
					'posts'    => Config::default_layout_for_type( 'posts' ),
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
					'direction' => $direction,
					'rows'      => $this->positive_int( $layout['rows'] ?? 1, 12 ),
					'columns'   => $this->positive_int( $layout['columns'] ?? 1, 12 ),
				),
			);

			if ( 'image' === $type ) {
				$clean['data'] = $this->sanitize_items( $section['data'] ?? array() );
			}

			if ( 'products' === $type ) {
				$per_page = absint( $section['per_page'] ?? 10 );
				$clean['per_page']          = min( 50, max( 1, $per_page ?: 10 ) );
				$clean['category']          = Request::ids( $section['category'] ?? array() );
				$clean['brand']             = Request::ids( $section['brand'] ?? array() );
				$clean['on_sale']           = ! empty( $section['on_sale'] );
				$clean['view_all_enabled']  = ! empty( $section['view_all_enabled'] );
				$clean['view_all_title']    = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
				$clean['view_all_action']   = Config::normalize_view_all_action( 'products', $section['view_all_action'] ?? array(), $clean['view_all_title'] );
			}

			if ( 'category' === $type || 'brand' === $type ) {
				$clean['include']           = Request::ids( $section['include'] ?? array() );
				if ( 'category' === $type ) {
					$source = sanitize_key( (string) ( $section['category_source'] ?? 'custom' ) );
					$clean['category_source'] = in_array( $source, array( 'custom', 'parent' ), true ) ? $source : 'custom';
					$clean['parent_category'] = absint( $section['parent_category'] ?? 0 );
				}
				$clean['view_all_enabled']  = ! empty( $section['view_all_enabled'] );
				$clean['view_all_title']    = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
				$clean['view_all_action']   = Config::normalize_view_all_action( $type, $section['view_all_action'] ?? array(), $clean['view_all_title'] );
			}

			if ( 'posts' === $type ) {
				$per_page = absint( $section['per_page'] ?? 10 );
				$clean['per_page']          = min( 50, max( 1, $per_page ?: 10 ) );
				$clean['category']          = Request::ids( $section['category'] ?? array() );
				$clean['view_all_enabled']  = ! empty( $section['view_all_enabled'] );
				$clean['view_all_title']    = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
				$clean['view_all_action']   = Config::normalize_view_all_action( 'posts', $section['view_all_action'] ?? array(), $clean['view_all_title'] );
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

	private function sanitize_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title  = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$action = isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : array();

			// Backward compatibility for fields used by versions before 2.2.4.
			if ( isset( $item['action_type'] ) ) {
				$action['type'] = $item['action_type'];
			}
			if ( array_key_exists( 'action_destination', $item ) ) {
				$action['destination'] = $item['action_destination'];
			} elseif ( array_key_exists( 'action_value', $item ) ) {
				$action['destination'] = $item['action_value'];
			}
			if ( isset( $item['action_on_sale'] ) ) {
				$action['on_sale'] = $item['action_on_sale'];
			}
			if ( isset( $item['action_orderby'] ) ) {
				$action['orderby'] = $item['action_orderby'];
			}
			if ( isset( $item['action_order'] ) ) {
				$action['order'] = $item['action_order'];
			}

			$resolved_action = $this->sanitize_action( $action, $title );
			if ( ! in_array( $resolved_action['type'], array( 'product', 'category', 'brand', 'url' ), true ) ) {
				$resolved_action = Config::default_action( $title, 'product' );
			}

			$result[] = array(
				'title'         => $title,
				'subtitle'      => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'attachment_id' => absint( $item['attachment_id'] ?? 0 ),
				'image'         => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
				'action'        => $resolved_action,
			);
		}

		return $result;
	}

	private function sanitize_action( $action, string $title ): array {
		return Config::normalize_action( is_array( $action ) ? $action : array(), $title );
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

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'application-api' ) );
		}

		$config = Config::home_configuration();
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
				<button type="button" class="app-api-tab" data-tab="products">محصولات</button>
				<button type="button" class="app-api-tab" data-tab="categories">دسته‌بندی‌ها</button>
				<button type="button" class="app-api-tab" data-tab="account">حساب کاربری</button>
			</nav>

			<section class="app-api-tab-panel is-active" data-panel="home">
				<form method="post" action="options.php" id="app-api-settings-form">
					<?php settings_fields( 'app_api_settings' ); ?>
					<?php $this->render_endpoint_settings( 'خانه', Config::OPTION_HOME_ENDPOINT, Config::home_endpoint(), '' ); ?>
					<?php $this->render_home_order( $config ); ?>

					<div class="app-api-section-heading">
						<div><h2>تنظیمات بخش‌های صفحه خانه</h2></div>
					</div>

					<div class="app-api-section-cards" id="app-api-section-cards">
						<?php foreach ( $config['order'] as $key ) : ?>
							<?php if ( isset( $config['sections'][ $key ] ) ) : ?>
								<?php $this->render_section_card( $key, $config['sections'][ $key ] ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>

					<div class="app-api-save-bar">
						<span>ترتیب بخش‌های فعال، دقیقاً ترتیب آرایه <code>sections</code> در API خانه خواهد بود.</span>
						<?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>

			<section class="app-api-tab-panel" data-panel="products">
				<?php $this->render_products_information(); ?>
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
				<div><h2>آدرس API <?php echo esc_html( $label ); ?></h2><p>نام endpoint صفحه خانه را می‌توانی با حروف انگلیسی، عدد، خط تیره یا زیرخط تغییر بدهی.</p></div>
			</div>
			<div class="app-api-field-grid two-columns">
				<label><span>نام endpoint</span><input type="text" dir="ltr" class="app-api-endpoint-slug" data-endpoint-suffix="<?php echo esc_attr( $suffix ); ?>" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $endpoint ); ?>"></label>
				<label><span>آدرس کامل API</span><input type="text" dir="ltr" class="app-api-endpoint-url" data-endpoint-base="<?php echo esc_attr( $base_url ); ?>" value="<?php echo esc_attr( $full_url ); ?>" readonly></label>
			</div>
		</div>
		<?php
	}

	private function render_products_information(): void {
		$list_url   = rest_url( Config::REST_NAMESPACE . '/products' );
		$detail_url = $list_url . '/{id}';
		?>
		<div class="app-api-products-info">
			<div class="app-api-card app-api-products-intro">
				<div class="app-api-card-title"><div><h2>API محصولات</h2><p>این API برای فهرست محصولات، صفحه محصولات، جست‌وجو، فیلتر و دریافت جزئیات یک محصول استفاده می‌شود. آدرس‌ها ثابت هستند و نیازی به تنظیم ندارند.</p></div></div>
				<div class="app-api-endpoint-list">
					<label><span>API فهرست محصولات</span><input type="text" dir="ltr" value="<?php echo esc_attr( $list_url ); ?>" readonly></label>
					<label><span>API جزئیات محصول</span><input type="text" dir="ltr" value="<?php echo esc_attr( $detail_url ); ?>" readonly></label>
				</div>
			</div>

			<div class="app-api-info-grid">
				<div class="app-api-card app-api-info-card">
					<h3>پارامترهای فهرست محصولات</h3>
					<p>پارامترها از طریق آدرس درخواست ارسال می‌شوند و می‌توان چند فیلتر را هم‌زمان ترکیب کرد.</p>
					<ul class="app-api-param-list">
						<li><code>page</code><span>شماره صفحه؛ پیش‌فرض ۱</span></li>
						<li><code>per_page</code><span>تعداد محصول در هر صفحه؛ پیش‌فرض <?php echo esc_html( Config::DEFAULT_PER_PAGE ); ?></span></li>
						<li><code>search</code><span>جست‌وجو در عنوان محصول و شناسه کالا (SKU)</span></li>
						<li><code>category</code><span>یک یا چند شناسه دسته‌بندی، جداشده با ویرگول</span></li>
						<li><code>brand</code><span>یک یا چند شناسه برند، جداشده با ویرگول</span></li>
						<li><code>attributes</code><span>فیلتر ویژگی‌ها؛ گزینه‌های یک ویژگی با هم «یا» و ویژگی‌های مختلف با هم «و» در نظر گرفته می‌شوند.</span></li>
						<li><code>min_price</code><span>حداقل قیمت</span></li>
						<li><code>max_price</code><span>حداکثر قیمت</span></li>
						<li><code>on_sale</code><span>با مقدار true فقط محصولات تخفیف‌دار و با false فقط محصولات بدون تخفیف نمایش داده می‌شوند.</span></li>
						<li><code>orderby</code><span>نوع مرتب‌سازی؛ پیش‌فرض تاریخ</span></li>
						<li><code>order</code><span>ترتیب صعودی یا نزولی؛ پیش‌فرض نزولی</span></li>
					</ul>
				</div>

				<div class="app-api-card app-api-info-card">
					<h3>اطلاعات فیلتر و فیلتر محلی</h3>
					<p>پاسخ API سه لیست <code>categories</code>، <code>brands</code> و <code>attributes</code> را داخل <code>filters</code> برمی‌گرداند تا رابط فیلتر از روی اطلاعات خود فروشگاه ساخته شود.</p>
					<ul class="app-api-bullet-list">
						<li>دسته‌بندی‌ها به‌صورت درختی و همراه زیر‌دسته‌ها برگردانده می‌شوند.</li>
						<li>برندها به ترتیب حروف الفبا قرار می‌گیرند.</li>
						<li>فقط ویژگی‌های سراسری که «بایگانی فعال شود؟» برای آن‌ها فعال است در فیلترها قرار می‌گیرند.</li>
						<li>هر گزینه ویژگی دارای شناسه، نام، رنگ و تصویر است؛ اگر رنگ یا تصویر ثبت نشده باشد مقدار آن خالی است.</li>
						<li>هر محصول در فهرست، علاوه بر اطلاعات کارت، <code>total_sales</code>، <code>average_rating</code>، دسته‌بندی، برند و ویژگی‌های شناسه‌محور را هم برمی‌گرداند تا بتوان فیلتر و شمارش نتایج را به‌صورت محلی انجام داد.</li>
					</ul>
				</div>
			</div>

			<div class="app-api-card app-api-request-example">
				<h3>نمونه فیلتر ویژگی‌ها</h3>
				<p>مثلاً برای انتخاب دو گزینه از ویژگی شماره ۱ و دو گزینه از ویژگی شماره ۱۸:</p>
				<code dir="ltr"><?php echo esc_html( $list_url . '?attributes[1]=118,119&attributes[18]=209,238' ); ?></code>
				<p>در <code>filter_by.attributes</code> همین انتخاب‌ها به شکل آرایه‌ای از شناسه ویژگی و شناسه گزینه‌ها برگردانده می‌شوند. در <code>filter_by</code> نام کلیدهای دسته‌بندی و برند نیز به‌ترتیب <code>categories</code> و <code>brands</code> است.</p>
			</div>

			<div class="app-api-card app-api-request-example">
				<h3>نمونه درخواست ترکیبی</h3>
				<code dir="ltr"><?php echo esc_html( $list_url . '?page=1&per_page=20&search=پردازنده&category=55&brand=313&attributes[1]=118,119&min_price=1000000&max_price=5000000&on_sale=true&orderby=date&order=desc' ); ?></code>
			</div>
		</div>
		<?php
	}

	private function render_home_order( array $config ): void {
		?>
		<details class="app-api-card app-api-order-card app-api-accordion" open>
			<summary>
				<div class="app-api-card-title-text"><h2>فعال‌سازی و ترتیب نمایش</h2><p>بخش‌های فعال را جابه‌جا کن؛ بخش غیرفعال از API و فهرست ترتیب حذف می‌شود، اما تنظیماتش حفظ می‌گردد.</p></div>
			</summary>
			<div class="app-api-order-body app-api-accordion-body">
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
		</details>
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
		<details class="app-api-section-card app-api-accordion <?php echo empty( $section['enabled'] ) ? 'is-disabled' : ''; ?>" data-section-card="<?php echo esc_attr( $key ); ?>" data-section-type="<?php echo esc_attr( $type ); ?>">
			<summary>
				<div><strong class="app-api-card-label"><?php echo esc_html( $label ); ?></strong><small><code class="app-api-card-id"><?php echo esc_html( $section['id'] ); ?></code> · <span class="app-api-card-type"><?php echo esc_html( Config::section_type_label( $type ) ); ?></span></small></div>
				<div class="app-api-summary-actions"><span class="app-api-status"><?php echo empty( $section['enabled'] ) ? 'غیرفعال' : 'فعال'; ?></span><button type="button" class="button-link-delete app-api-delete-section">حذف بخش</button></div>
			</summary>
			<div class="app-api-section-body app-api-accordion-body">
				<div class="app-api-field-grid four-columns">
					<label><span>شناسه دلخواه بخش</span><input type="text" dir="ltr" class="app-api-section-id-input" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $section['id'] ); ?>" placeholder="مثلاً amazing_offers"><small>فقط حروف انگلیسی، عدد، خط تیره و زیرخط</small></label>
					<label><span>نوع بخش</span><input type="text" dir="ltr" class="app-api-section-type-display" value="<?php echo esc_attr( $type ); ?>" readonly><input type="hidden" class="app-api-section-type-select" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $type ); ?>"><small>نوع این بخش ثابت است.</small></label>
					<label><span>عنوان بخش</span><input type="text" class="app-api-section-title-input" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $section['title'] ?? '' ); ?>" placeholder="عنوان قابل نمایش"></label>
					<label><span>زیرعنوان بخش</span><input type="text" name="<?php echo esc_attr( $name ); ?>[subtitle]" value="<?php echo esc_attr( $section['subtitle'] ?? '' ); ?>" placeholder="متن کوتاه زیر عنوان"></label>
				</div>

				<div class="app-api-subcard">
					<div class="app-api-subcard-heading"><div><h4>چیدمان خروجی</h4></div></div>
					<div class="app-api-field-grid three-columns">
						<label><span>جهت نمایش</span><select name="<?php echo esc_attr( $name ); ?>[layout][direction]"><option value="horizontal" <?php selected( $layout['direction'] ?? '', 'horizontal' ); ?>>افقی</option><option value="vertical" <?php selected( $layout['direction'] ?? '', 'vertical' ); ?>>عمودی</option></select></label>
						<label><span>تعداد سطر</span><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[layout][rows]" value="<?php echo esc_attr( $layout['rows'] ?? 1 ); ?>" placeholder="پیش‌فرض 1"></label>
						<label><span>تعداد ستون</span><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[layout][columns]" value="<?php echo esc_attr( $layout['columns'] ?? 1 ); ?>" placeholder="پیش‌فرض 1"></label>
					</div>
				</div>

				<div class="app-api-type-panel" data-type-panel="products" <?php echo 'products' !== $type ? 'hidden' : ''; ?>><?php $this->render_products_settings( $name, $section ); ?></div>
				<div class="app-api-type-panel" data-type-panel="category" <?php echo 'category' !== $type ? 'hidden' : ''; ?>><?php $this->render_terms_settings( $name, $section, 'category' ); ?></div>
				<div class="app-api-type-panel" data-type-panel="brand" <?php echo 'brand' !== $type ? 'hidden' : ''; ?>><?php $this->render_terms_settings( $name, $section, 'brand' ); ?></div>
				<div class="app-api-type-panel" data-type-panel="posts" <?php echo 'posts' !== $type ? 'hidden' : ''; ?>><?php $this->render_posts_settings( $name, $section ); ?></div>
				<div class="app-api-type-panel" data-type-panel="image" <?php echo 'image' !== $type ? 'hidden' : ''; ?>><?php $this->render_repeater( $key, $section['data'] ?? array(), $name ); ?></div>
			</div>
		</details>
		<?php
	}

	private function render_products_settings( string $name, array $section ): void {
		$view_all_enabled = array_key_exists( 'view_all_enabled', $section ) ? ! empty( $section['view_all_enabled'] ) : true;
		$view_all_title   = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
		$view_all_action  = isset( $section['view_all_action'] ) && is_array( $section['view_all_action'] )
			? $section['view_all_action']
			: array();
		?>
		<div class="app-api-subcard">
			<div class="app-api-subcard-heading"><div><h4>منبع و فیلتر محصولات</h4><p>چند دسته یا چند برند در هر فیلد با رابطه «یا» و دسته با برند با رابطه «و» فیلتر می‌شوند.</p></div></div>
			<div class="app-api-field-grid three-columns">
				<label><span>تعداد نمایش (per_page)</span><input type="number" min="1" max="50" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( $section['per_page'] ?? 10 ); ?>" placeholder="پیش‌فرض 10"></label>
				<label><span>شناسه دسته‌بندی‌ها</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[category]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['category'] ?? array() ) ) ); ?>" placeholder="مثلاً 166,350"></label>
				<label><span>شناسه برندها</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[brand]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['brand'] ?? array() ) ) ); ?>" placeholder="مثلاً 313,362"></label>
			</div>
			<label class="app-api-check app-api-sale-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on_sale]" value="1" <?php checked( ! empty( $section['on_sale'] ) ); ?>> فقط محصولات تخفیف‌دار نمایش داده شوند</label>
			<p class="description">اگر دسته‌بندی و برند خالی باشند، محصولات کل فروشگاه نمایش داده می‌شوند.</p>
		</div>
		<?php $this->render_view_all_settings( $name, $view_all_title, $view_all_action, $view_all_enabled, 'products' ); ?>
		<?php
	}

	private function render_terms_settings( string $name, array $section, string $type ): void {
		$is_brand         = 'brand' === $type;
		$view_all_enabled = array_key_exists( 'view_all_enabled', $section ) ? ! empty( $section['view_all_enabled'] ) : true;
		$view_all_title   = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
		$view_all_action  = isset( $section['view_all_action'] ) && is_array( $section['view_all_action'] )
			? $section['view_all_action']
			: array();
		$category_source  = sanitize_key( (string) ( $section['category_source'] ?? 'custom' ) );
		$category_source  = in_array( $category_source, array( 'custom', 'parent' ), true ) ? $category_source : 'custom';
		$parent_category  = absint( $section['parent_category'] ?? 0 );
		?>
		<div class="app-api-subcard">
			<div class="app-api-subcard-heading"><div><h4><?php echo $is_brand ? 'برندهای انتخابی' : 'دسته‌بندی‌های انتخابی'; ?></h4><p><?php echo $is_brand ? 'فقط برندهایی که مشخص می‌کنی و دقیقاً با همان ترتیب در API نمایش داده می‌شوند.' : 'دسته‌بندی‌ها را می‌توانی با شناسه‌های دلخواه یا با انتخاب یک دسته‌بندی والد تعیین کنی.'; ?></p></div></div>
			<?php if ( $is_brand ) : ?>
				<label><span>شناسه برندها به ترتیب نمایش</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[include]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['include'] ?? array() ) ) ); ?>" placeholder="مثلاً 313,362,367"></label>
			<?php else : ?>
				<div class="app-api-category-selector" data-category-selector>
					<div class="app-api-field-grid two-columns">
						<label><span>روش انتخاب دسته‌بندی‌ها</span><select class="app-api-category-source" name="<?php echo esc_attr( $name ); ?>[category_source]"><option value="custom" <?php selected( $category_source, 'custom' ); ?>>انتخاب سفارشی با شناسه</option><option value="parent" <?php selected( $category_source, 'parent' ); ?>>نمایش زیر‌دسته‌های یک دسته‌بندی والد</option></select></label>
						<label class="app-api-parent-category-field" <?php echo 'parent' === $category_source ? '' : 'hidden'; ?>><span>دسته‌بندی والد</span><select name="<?php echo esc_attr( $name ); ?>[parent_category]"><option value="0">انتخاب دسته‌بندی والد</option><?php $this->render_parent_product_category_options( $parent_category ); ?></select></label>
					</div>
					<label class="app-api-custom-category-field" <?php echo 'custom' === $category_source ? '' : 'hidden'; ?>><span>شناسه دسته‌بندی‌ها به ترتیب نمایش</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[include]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['include'] ?? array() ) ) ); ?>" placeholder="مثلاً 55,166,350"></label>
					<p class="description app-api-parent-category-help" <?php echo 'parent' === $category_source ? '' : 'hidden'; ?>>در این حالت، همه زیر‌دسته‌های مستقیم دسته‌بندی والد انتخاب‌شده با ترتیب تعریف‌شده در فروشگاه نمایش داده می‌شوند.</p>
				</div>
			<?php endif; ?>
		</div>
		<?php $this->render_view_all_settings( $name, $view_all_title, $view_all_action, $view_all_enabled, $type ); ?>
		<?php
	}

	private function render_parent_product_category_options( int $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => 0,
				'meta_key'   => 'order',
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $term->term_id, selected( $selected, (int) $term->term_id, false ), esc_html( $term->name ) );
		}
	}

	private function render_posts_settings( string $name, array $section ): void {
		$view_all_enabled = array_key_exists( 'view_all_enabled', $section ) ? ! empty( $section['view_all_enabled'] ) : true;
		$view_all_title   = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
		$view_all_action  = isset( $section['view_all_action'] ) && is_array( $section['view_all_action'] )
			? $section['view_all_action']
			: array();
		?>
		<div class="app-api-subcard">
			<div class="app-api-subcard-heading"><div><h4>منبع و فیلتر نوشته‌ها</h4><p>اگر شناسه دسته‌بندی وارد نشود، جدیدترین نوشته‌های منتشرشده از همه دسته‌بندی‌ها نمایش داده می‌شوند.</p></div></div>
			<div class="app-api-field-grid two-columns">
				<label><span>تعداد نمایش (per_page)</span><input type="number" min="1" max="50" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( $section['per_page'] ?? 10 ); ?>" placeholder="پیش‌فرض 10"></label>
				<label><span>شناسه دسته‌بندی‌های نوشته</span><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[category]" value="<?php echo esc_attr( implode( ',', Request::ids( $section['category'] ?? array() ) ) ); ?>" placeholder="مثلاً 4,9,12"></label>
			</div>
		</div>
		<?php $this->render_view_all_settings( $name, $view_all_title, $view_all_action, $view_all_enabled, 'posts' ); ?>
		<?php
	}

	private function view_all_action_rules( string $section_type ): array {
		switch ( sanitize_key( $section_type ) ) {
			case 'products':
				return array(
					'allowed'    => array( 'all', 'category', 'brand' ),
					'labels'     => array( 'all' => 'همه محصولات' ),
					'allow_sale' => true,
				);
			case 'category':
				return array(
					'allowed'    => array( 'all' ),
					'labels'     => array( 'all' => 'همه دسته‌بندی‌ها' ),
					'allow_sale' => false,
				);
			case 'brand':
				return array(
					'allowed'    => array( 'all' ),
					'labels'     => array( 'all' => 'همه برندها' ),
					'allow_sale' => false,
				);
			case 'posts':
				return array(
					'allowed'    => array( 'all', 'category' ),
					'labels'     => array( 'all' => 'همه نوشته‌ها' ),
					'allow_sale' => false,
				);
		}
		return array( 'allowed' => array( 'all' ), 'labels' => array( 'all' => 'همه موارد' ), 'allow_sale' => false );
	}

	private function render_view_all_settings( string $section_name, string $title, array $action, bool $enabled, string $section_type ): void {
		$rules = $this->view_all_action_rules( $section_type );
		?>
		<div class="app-api-subcard app-api-view-all-settings">
			<div class="app-api-subcard-heading"><div><h4>مشاهده همه</h4><p>اگر غیرفعال شود، پارامتر <code>view_all</code> به‌طور کامل از خروجی همان بخش حذف می‌شود.</p></div></div>
			<label class="app-api-check app-api-view-all-toggle"><input type="checkbox" class="app-api-view-all-enabled" name="<?php echo esc_attr( $section_name ); ?>[view_all_enabled]" value="1" <?php checked( $enabled ); ?>> فعال کردن مشاهده همه</label>
			<div class="app-api-view-all-fields" <?php echo $enabled ? '' : 'hidden'; ?>>
				<label class="app-api-view-all-title"><span>عنوان مشاهده همه</span><input type="text" name="<?php echo esc_attr( $section_name ); ?>[view_all_title]" value="<?php echo esc_attr( $title ); ?>"></label>
				<?php $this->render_action_editor( $section_name . '[view_all_action]', $action, $title, $rules['allowed'], (bool) $rules['allow_sale'], $rules['labels'] ); ?>
			</div>
		</div>
		<?php
	}

	private function render_repeater( string $section_key, array $items, string $base_name ): void {
		?>
		<div class="app-api-subcard app-api-repeater" data-section="<?php echo esc_attr( $section_key ); ?>" data-kind="image">
			<div class="app-api-repeater-header"><div><h4>لیست تصاویر</h4><p>تصویر، عنوان، زیرعنوان و مقصد هر آیتم را مشخص کن و ترتیب نمایش را با درگ تغییر بده.</p></div><button type="button" class="button button-secondary app-api-add-item"><span class="dashicons dashicons-plus-alt2"></span> افزودن تصویر</button></div>
			<div class="app-api-items-sortable">
				<?php foreach ( $items as $index => $item ) : ?>
					<?php $this->render_repeater_item( (string) $index, is_array( $item ) ? $item : array(), $base_name ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_repeater_item( string $index, array $item, string $base_name ): void {
		$item_name     = $base_name . '[data][' . $index . ']';
		$title         = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		$action        = isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : array();
		$image         = esc_url( (string) ( $item['image'] ?? '' ) );
		$attachment_id = absint( $item['attachment_id'] ?? 0 );
		?>
		<div class="app-api-repeater-item" data-item-index="<?php echo esc_attr( $index ); ?>">
			<div class="app-api-item-topbar"><span class="dashicons dashicons-move app-api-item-drag"></span><strong><?php echo esc_html( $title ?: 'تصویر' ); ?></strong><button type="button" class="button-link-delete app-api-remove-item">حذف</button></div>
			<div class="app-api-item-content">
				<div class="app-api-media-field">
					<div class="app-api-image-preview <?php echo $image ? 'has-image' : ''; ?>"><?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" alt=""><?php else : ?><span class="dashicons dashicons-format-image"></span><small>تصویری انتخاب نشده</small><?php endif; ?></div>
					<input type="hidden" class="app-api-attachment-id" name="<?php echo esc_attr( $item_name ); ?>[attachment_id]" value="<?php echo esc_attr( $attachment_id ); ?>">
					<input type="hidden" class="app-api-image-url" name="<?php echo esc_attr( $item_name ); ?>[image]" value="<?php echo esc_attr( $image ); ?>">
					<button type="button" class="button app-api-select-image">انتخاب تصویر</button>
				</div>
				<div class="app-api-item-fields">
					<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" class="app-api-image-item-title" name="<?php echo esc_attr( $item_name ); ?>[title]" value="<?php echo esc_attr( $title ); ?>"></label><label><span>زیرعنوان</span><input type="text" name="<?php echo esc_attr( $item_name ); ?>[subtitle]" value="<?php echo esc_attr( $item['subtitle'] ?? '' ); ?>"></label></div>
					<?php $this->render_action_editor( $item_name . '[action]', $action, $title, array( 'product', 'category', 'brand', 'url' ), true ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_action_editor( string $name, array $action, string $title, array $allowed_types, bool $allow_on_sale = true, array $custom_labels = array() ): void {
		$allowed_types = array_values( array_filter( array_map( 'sanitize_key', $allowed_types ) ) );
		$action         = Config::normalize_action( $action, $title );
		$action_type    = (string) ( $action['type'] ?? '' );
		if ( ! in_array( $action_type, $allowed_types, true ) ) {
			$action_type = $allowed_types[0] ?? 'product';
			$action      = Config::default_action( $title, $action_type );
		}
		$destination   = 'url' === $action_type ? (string) ( $action['url'] ?? '' ) : (string) ( $action['destination_id'] ?? '' );
		$is_identifier = in_array( $action_type, array( 'product', 'category', 'brand' ), true );
		$is_url        = 'url' === $action_type;
		$is_all        = 'all' === $action_type;
		$is_sale_type  = $allow_on_sale && in_array( $action_type, array( 'category', 'brand' ), true );
		$label         = $is_url ? 'لینک مقصد' : ( $is_identifier ? 'شناسه مقصد' : 'بدون شناسه مقصد' );
		?>
		<div class="app-api-action-editor app-api-action-fields" data-action-title="<?php echo esc_attr( $title ); ?>" data-allow-sale="<?php echo $allow_on_sale ? '1' : '0'; ?>">
			<div class="app-api-field-grid four-columns">
				<label><span>نوع مقصد</span><select name="<?php echo esc_attr( $name ); ?>[type]" class="app-api-action-type"><?php $this->render_action_options( $action_type, $allowed_types, $custom_labels ); ?></select></label>
				<label class="app-api-destination-field" <?php echo $is_all ? 'hidden' : ''; ?>><span class="app-api-destination-label"><?php echo esc_html( $label ); ?></span><input type="text" dir="ltr" class="app-api-action-destination" name="<?php echo esc_attr( $name ); ?>[destination]" value="<?php echo esc_attr( $destination ); ?>" <?php disabled( ! $is_identifier && ! $is_url ); ?>></label>
				<label><span>مرتب‌سازی</span><select name="<?php echo esc_attr( $name ); ?>[orderby]" class="app-api-action-orderby"><?php $this->render_action_orderby_options( (string) $action['orderby'] ); ?></select></label>
				<label><span>ترتیب</span><select name="<?php echo esc_attr( $name ); ?>[order]"><option value="desc" <?php selected( $action['order'], 'desc' ); ?>>نزولی</option><option value="asc" <?php selected( $action['order'], 'asc' ); ?>>صعودی</option></select></label>
			</div>
			<label class="app-api-check app-api-action-sale-field" <?php echo $is_sale_type ? '' : 'hidden'; ?>><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on_sale]" value="1" <?php checked( true === $action['on_sale'] ); ?> <?php disabled( ! $is_sale_type ); ?>> فقط محصولات تخفیف‌دار نمایش داده شوند</label>
		</div>
		<?php
	}

	private function render_action_options( string $selected, array $allowed_types, array $custom_labels = array() ): void {
		$options = array(
			'all'      => 'همه موارد',
			'product'  => 'محصول',
			'category' => 'دسته‌بندی',
			'brand'    => 'برند',
			'url'      => 'لینک',
		);
		$options = array_replace( $options, $custom_labels );
		$allowed = array_fill_keys( array_map( 'sanitize_key', $allowed_types ), true );
		$options = array_intersect_key( $options, $allowed );
		if ( ! isset( $options[ $selected ] ) ) {
			$selected = (string) array_key_first( $options );
		}
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
	}

	private function render_action_orderby_options( string $selected ): void {
		$options = array(
			'date'       => 'تاریخ',
			'price'      => 'قیمت',
			'popularity' => 'محبوبیت',
			'rating'     => 'امتیاز',
			'cout_sales' => 'تعداد فروش',
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
