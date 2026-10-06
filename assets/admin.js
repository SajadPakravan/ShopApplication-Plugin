(function ($) {
	'use strict';

	var optionName = (window.appApiAdmin && appApiAdmin.optionName) || 'app_api_home_configuration';

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>'"]/g, function (char) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char];
		});
	}

	function typeLabel(type) {
		var names = (window.appApiAdmin && appApiAdmin.sectionTypeNames) || {};
		if (type === 'menu') {
			return 'منوی دسترسی سریع';
		}
		return names[type] || type;
	}

	function orderItem(key, label, type) {
		return '<li class="app-api-order-item" data-section-id="' + escapeHtml(key) + '">' +
			'<span class="dashicons dashicons-move app-api-drag-handle" aria-hidden="true"></span>' +
			'<div class="app-api-order-name"><strong>' + escapeHtml(label) + '</strong><small>' + escapeHtml(typeLabel(type)) + '</small></div>' +
			'<div class="app-api-order-actions">' +
			'<button type="button" class="button app-api-move-up" aria-label="انتقال به بالا"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
			'<button type="button" class="button app-api-move-down" aria-label="انتقال به پایین"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
			'</div></li>';
	}

	function toggleItem(key, id, type) {
		var name = optionName + '[sections][' + key + '][enabled]';
		return '<label class="app-api-toggle" data-toggle-section="' + escapeHtml(key) + '" data-label="' + escapeHtml(id) + '" data-type="' + escapeHtml(type) + '">' +
			'<input type="checkbox" class="app-api-section-enabled" name="' + escapeHtml(name) + '" value="1" checked>' +
			'<span class="app-api-switch"></span><span class="app-api-toggle-label">' + escapeHtml(id) + '</span></label>';
	}

	function updateOrderInput() {
		var ordered = [];
		$('#app-api-active-sections .app-api-order-item').each(function () {
			ordered.push(String($(this).data('section-id')));
		});
		$('#app-api-section-toggles .app-api-toggle').each(function () {
			var key = String($(this).data('toggle-section'));
			if (ordered.indexOf(key) === -1) {
				ordered.push(key);
			}
		});
		$('#app-api-section-order').val(ordered.join(','));
	}

	function initSortables() {
		$('#app-api-active-sections').sortable({
			handle: '.app-api-drag-handle',
			placeholder: 'app-api-order-item ui-sortable-placeholder',
			update: updateOrderInput
		});
		$('.app-api-items-sortable').sortable({
			handle: '.app-api-item-drag',
			placeholder: 'app-api-repeater-item ui-sortable-placeholder'
		});
	}

	function actionOptions() {
		var options = {
			none: 'بدون عملیات', category: 'دسته‌بندی محصول', product: 'محصول', products: 'صفحه محصولات',
			brand: 'برند', tag: 'برچسب', url: 'لینک دلخواه', app_download: 'دانلود اپلیکیشن',
			purchase_consulting: 'مشاوره خرید', goods_order: 'سفارش اجناس', assembly_order: 'سفارش مونتاژ',
			repair_order: 'سفارش تعمیرات', return_request: 'درخواست مرجوعی', store_payment: 'پرداخت فروشگاه', survey: 'نظرسنجی'
		};
		return Object.keys(options).map(function (key) {
			return '<option value="' + key + '">' + options[key] + '</option>';
		}).join('');
	}

	function newRepeaterItem(sectionKey, kind) {
		var index = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
		var base = optionName + '[sections][' + sectionKey + '][data][' + index + ']';
		var isMenu = kind === 'menu';
		var imageButton = isMenu ? 'انتخاب آیکون' : 'انتخاب تصویر';
		var itemTitle = isMenu ? 'گزینه منو' : 'بنر جدید';
		var fields = '';

		if (isMenu) {
			fields = '<div class="app-api-field-grid three-columns"><label><span>ID آیتم</span><input type="text" dir="ltr" name="' + base + '[id]" value="" placeholder="اختیاری"></label>' +
				'<label><span>عنوان</span><input type="text" name="' + base + '[title]" value=""></label>' +
				'<label><span>زیرعنوان</span><input type="text" name="' + base + '[subtitle]" value=""></label></div>' +
				'<div class="app-api-field-grid two-columns"><label><span>نوع مقصد</span><select name="' + base + '[action_type]" class="app-api-action-type">' + actionOptions() + '</select></label>' +
				'<label><span>آیدی یا لینک مقصد</span><input type="text" dir="ltr" name="' + base + '[action_value]" value="" placeholder="مثلاً 350 یا https://..."></label></div>';
		} else {
			fields = '<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" name="' + base + '[title]" value=""></label>' +
				'<label><span>زیرعنوان</span><input type="text" name="' + base + '[subtitle]" value=""></label></div>';
		}

		return '<div class="app-api-repeater-item" data-item-index="' + index + '">' +
			'<div class="app-api-item-topbar"><span class="dashicons dashicons-move app-api-item-drag"></span><strong>' + itemTitle + '</strong><button type="button" class="button-link-delete app-api-remove-item">حذف</button></div>' +
			'<div class="app-api-item-content">' +
			'<div class="app-api-media-field"><div class="app-api-image-preview"><span class="dashicons dashicons-format-image"></span><small>تصویری انتخاب نشده</small></div>' +
			'<input type="hidden" class="app-api-attachment-id" name="' + base + '[attachment_id]" value="">' +
			'<input type="hidden" class="app-api-image-url" name="' + base + '[image]" value="">' +
			'<button type="button" class="button app-api-select-image">' + imageButton + '</button></div>' +
			'<div class="app-api-item-fields">' + fields + '</div></div></div>';
	}

	function sectionLabel($card) {
		var title = $.trim($card.find('.app-api-section-title-input').first().val() || '');
		var id = $.trim($card.find('.app-api-section-id-input').first().val() || '');
		var type = $card.find('.app-api-section-type-select').first().val() || 'products';
		return title || id || typeLabel(type);
	}

	function syncSectionIdentity($card) {
		var key = String($card.data('section-card'));
		var type = $card.find('.app-api-section-type-select').first().val() || 'products';
		var id = $.trim($card.find('.app-api-section-id-input').first().val() || '');
		var label = sectionLabel($card);
		var $toggle = $('.app-api-toggle[data-toggle-section="' + key + '"]');
		var $order = $('.app-api-order-item[data-section-id="' + key + '"]');

		$card.find('.app-api-card-label').first().text(label);
		$card.find('.app-api-card-id').first().text(id || key);
		$card.find('.app-api-card-type').first().text(typeLabel(type));
		$toggle.attr('data-label', label).attr('data-type', type).find('.app-api-toggle-label').text(label);
		$order.find('.app-api-order-name strong').text(label);
		$order.find('.app-api-order-name small').text(typeLabel(type));
	}

	function setTypePanelState($card, type) {
		$card.attr('data-section-type', type);
		$card.find('.app-api-type-panel').each(function () {
			var $panel = $(this);
			var active = $panel.data('type-panel') === type;
			$panel.prop('hidden', !active);
			$panel.find(':input').prop('disabled', !active);
		});
	}

	function applyType($card, type, resetLayout) {
		var defaults = (window.appApiAdmin && appApiAdmin.defaultLayouts && appApiAdmin.defaultLayouts[type]) || {};
		$card.find('.app-api-section-type-select').val(type);
		$card.find('.app-api-section-type-display').val(type);
		setTypePanelState($card, type);
		if (resetLayout) {
			$card.find('input[name$="[layout][component]"]').val(defaults.component || type);
			$card.find('select[name$="[layout][direction]"]').val(defaults.direction || 'horizontal');
			$card.find('input[name$="[layout][rows]"]').val(defaults.rows || 1);
			$card.find('input[name$="[layout][columns]"]').val(defaults.columns || 1);
		}
		syncSectionIdentity($card);
	}

	function buildSectionFromTemplate(key, type) {
		var template = $('#app-api-section-template').html() || '';
		var id = type + '_' + Date.now();
		template = template.split('__KEY__').join(key).split('__ID__').join(id);
		var $card = $(template.trim());
		$card.data('section-card', key).attr('data-section-card', key);
		applyType($card, type, true);
		$card.find('.app-api-section-id-input').val(id);
		$card.find('.app-api-section-title-input').val('');
		$card.find('input[name$="[subtitle]"]').first().val('');
		$card.find('.app-api-status').first().text('فعال');
		$card.removeClass('is-disabled').attr('open', true);
		syncSectionIdentity($card);
		return { card: $card, id: id };
	}

	$(function () {
		initSortables();

		$('.app-api-section-card').each(function () {
			var $card = $(this);
			setTypePanelState($card, $card.find('.app-api-section-type-select').first().val() || $card.data('section-type'));
		});

		$('.app-api-tab').on('click', function () {
			var tab = $(this).data('tab');
			$('.app-api-tab').removeClass('is-active');
			$(this).addClass('is-active');
			$('.app-api-tab-panel').removeClass('is-active');
			$('.app-api-tab-panel[data-panel="' + tab + '"]').addClass('is-active');
		});

		$('#app-api-add-section').on('click', function () {
			var type = $('#app-api-new-section-type').val() || 'products';
			var key = 'section_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
			var built = buildSectionFromTemplate(key, type);

			$('#app-api-section-toggles').append(toggleItem(key, built.id, type));
			$('#app-api-active-sections').append(orderItem(key, built.id, type));
			$('#app-api-section-cards').append(built.card);
			built.card.find('.app-api-items-sortable').sortable({
				handle: '.app-api-item-drag',
				placeholder: 'app-api-repeater-item ui-sortable-placeholder'
			});
			updateOrderInput();
			$('html, body').animate({ scrollTop: built.card.offset().top - 80 }, 300);
		});

		$(document).on('change', '.app-api-section-enabled', function () {
			var $toggle = $(this).closest('.app-api-toggle');
			var key = String($toggle.data('toggle-section'));
			var $card = $('.app-api-section-card[data-section-card="' + key + '"]');
			var type = $card.find('.app-api-section-type-select').first().val() || $toggle.data('type');
			var label = sectionLabel($card);
			if (this.checked) {
				if (!$('#app-api-active-sections .app-api-order-item[data-section-id="' + key + '"]').length) {
					$('#app-api-active-sections').append(orderItem(key, label, type));
				}
				$card.removeClass('is-disabled').find('.app-api-status').first().text('فعال');
			} else {
				$('#app-api-active-sections .app-api-order-item[data-section-id="' + key + '"]').remove();
				$card.addClass('is-disabled').find('.app-api-status').first().text('غیرفعال');
			}
			updateOrderInput();
		});

		$(document).on('click', '.app-api-move-up', function () {
			var $item = $(this).closest('.app-api-order-item');
			if ($item.prev().length) {
				$item.prev().before($item);
			}
			updateOrderInput();
		});
		$(document).on('click', '.app-api-move-down', function () {
			var $item = $(this).closest('.app-api-order-item');
			if ($item.next().length) {
				$item.next().after($item);
			}
			updateOrderInput();
		});


		$(document).on('input', '.app-api-section-title-input, .app-api-section-id-input', function () {
			syncSectionIdentity($(this).closest('.app-api-section-card'));
		});

		$(document).on('click', '.app-api-delete-section', function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (!window.confirm(appApiAdmin.deleteSection || 'این بخش حذف شود؟')) {
				return;
			}
			var $card = $(this).closest('.app-api-section-card');
			var key = String($card.data('section-card'));
			$('.app-api-toggle[data-toggle-section="' + key + '"]').remove();
			$('.app-api-order-item[data-section-id="' + key + '"]').remove();
			$card.remove();
			updateOrderInput();
		});

		$(document).on('click', '.app-api-add-item', function () {
			var $repeater = $(this).closest('.app-api-repeater');
			$repeater.find('.app-api-items-sortable').append(newRepeaterItem($repeater.data('section'), $repeater.data('kind')));
		});
		$(document).on('click', '.app-api-remove-item', function () {
			if (window.confirm(appApiAdmin.deleteItem || 'این آیتم حذف شود؟')) {
				$(this).closest('.app-api-repeater-item').remove();
			}
		});
		$(document).on('input', '.app-api-repeater-item input[name$="[title]"]', function () {
			$(this).closest('.app-api-repeater-item').find('.app-api-item-topbar strong').text($(this).val() || 'آیتم بدون عنوان');
		});

		$(document).on('click', '.app-api-select-image', function (event) {
			event.preventDefault();
			var $field = $(this).closest('.app-api-media-field');
			var frame = wp.media({
				title: appApiAdmin.mediaTitle,
				button: { text: appApiAdmin.mediaButton },
				multiple: false,
				library: { type: 'image' }
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var url = attachment.url || (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) || '';
				$field.find('.app-api-attachment-id').val(attachment.id || '');
				$field.find('.app-api-image-url').val(url);
				$field.find('.app-api-image-preview').addClass('has-image').html('<img src="' + escapeHtml(url) + '" alt="">');
			});
			frame.open();
		});

		$(document).on('input', '.app-api-endpoint-slug', function () {
			var $input = $(this);
			var slug = String($input.val() || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[-_]+|[-_]+$/g, '');
			var $card = $input.closest('.app-api-endpoint-card');
			var $url = $card.find('.app-api-endpoint-url');
			$url.val(String($url.data('endpoint-base') || '') + slug + String($input.data('endpoint-suffix') || ''));
		});

		$('#app-api-settings-form').on('submit', function () {
			updateOrderInput();
			$('.app-api-section-card').each(function () {
				var $card = $(this);
				setTypePanelState($card, $card.find('.app-api-section-type-select').first().val());
			});
		});
	});
})(jQuery);
