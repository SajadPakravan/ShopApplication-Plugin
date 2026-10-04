(function ($) {
	'use strict';

	var optionName = (window.appApiAdmin && appApiAdmin.optionName) || 'app_api_home_configuration';

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>'"]/g, function (char) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char];
		});
	}

	function orderItem(id, label, type) {
		return '<li class="app-api-order-item" data-section-id="' + escapeHtml(id) + '">' +
			'<span class="dashicons dashicons-move app-api-drag-handle" aria-hidden="true"></span>' +
			'<div class="app-api-order-name"><strong>' + escapeHtml(label) + '</strong><small>' + escapeHtml(type) + '</small></div>' +
			'<div class="app-api-order-actions">' +
			'<button type="button" class="button app-api-move-up" aria-label="انتقال به بالا"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
			'<button type="button" class="button app-api-move-down" aria-label="انتقال به پایین"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
			'</div></li>';
	}

	function updateOrderInput() {
		var active = [];
		$('#app-api-active-sections .app-api-order-item').each(function () {
			active.push($(this).data('section-id'));
		});
		$('.app-api-toggle').each(function () {
			var id = $(this).data('toggle-section');
			if (active.indexOf(id) === -1) {
				active.push(id);
			}
		});
		$('#app-api-section-order').val(active.join(','));
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

	function newRepeaterItem(sectionId, kind) {
		var index = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
		var base = optionName + '[sections][' + sectionId + '][data][' + index + ']';
		var imageButton = kind === 'menu' ? 'انتخاب آیکون' : 'انتخاب تصویر';
		var itemTitle = kind === 'menu' ? 'گزینه منو' : 'بنر جدید';

		return '<div class="app-api-repeater-item" data-item-index="' + index + '">' +
			'<div class="app-api-item-topbar"><span class="dashicons dashicons-move app-api-item-drag"></span><strong>' + itemTitle + '</strong><button type="button" class="button-link-delete app-api-remove-item">حذف</button></div>' +
			'<div class="app-api-item-content">' +
			'<div class="app-api-media-field"><div class="app-api-image-preview"><span class="dashicons dashicons-format-image"></span><small>تصویری انتخاب نشده</small></div>' +
			'<input type="hidden" class="app-api-attachment-id" name="' + base + '[attachment_id]" value="">' +
			'<input type="hidden" class="app-api-image-url" name="' + base + '[image]" value="">' +
			'<button type="button" class="button app-api-select-image">' + imageButton + '</button></div>' +
			'<div class="app-api-item-fields"><input type="hidden" name="' + base + '[id]" value="">' +
			'<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" class="app-api-item-title-input" name="' + base + '[title]" value=""></label>' +
			'<label><span>زیرعنوان</span><input type="text" name="' + base + '[subtitle]" value=""></label></div>' +
			'<div class="app-api-field-grid two-columns"><label><span>نوع مقصد</span><select name="' + base + '[action_type]" class="app-api-action-type">' + actionOptions() + '</select></label>' +
			'<label><span>آیدی یا لینک مقصد</span><input type="text" dir="ltr" name="' + base + '[action_value]" value="" placeholder="مثلاً 350 یا https://..."></label></div>' +
			'</div></div></div>';
	}

	$(function () {
		initSortables();

		$('.app-api-tab').on('click', function () {
			var tab = $(this).data('tab');
			$('.app-api-tab').removeClass('is-active');
			$(this).addClass('is-active');
			$('.app-api-tab-panel').removeClass('is-active');
			$('.app-api-tab-panel[data-panel="' + tab + '"]').addClass('is-active');
		});

		$(document).on('change', '.app-api-section-enabled', function () {
			var $toggle = $(this).closest('.app-api-toggle');
			var id = $toggle.data('toggle-section');
			var $card = $('.app-api-section-card[data-section-card="' + id + '"]');
			if (this.checked) {
				if (!$('#app-api-active-sections .app-api-order-item[data-section-id="' + id + '"]').length) {
					$('#app-api-active-sections').append(orderItem(id, $toggle.data('label'), $toggle.data('type')));
				}
				$card.removeClass('is-disabled').find('.app-api-status').first().text('فعال');
			} else {
				$('#app-api-active-sections .app-api-order-item[data-section-id="' + id + '"]').remove();
				$card.addClass('is-disabled').find('.app-api-status').first().text('غیرفعال');
			}
			updateOrderInput();
		});

		$(document).on('click', '.app-api-move-up', function () {
			var $item = $(this).closest('.app-api-order-item');
			$item.prev().before($item);
			updateOrderInput();
		});
		$(document).on('click', '.app-api-move-down', function () {
			var $item = $(this).closest('.app-api-order-item');
			$item.next().after($item);
			updateOrderInput();
		});

		$(document).on('click', '.app-api-add-item', function () {
			var $repeater = $(this).closest('.app-api-repeater');
			$repeater.find('.app-api-items-sortable').append(newRepeaterItem($repeater.data('section'), $repeater.data('kind')));
		});
		$(document).on('click', '.app-api-remove-item', function () {
			if (window.confirm('این آیتم حذف شود؟')) {
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

		$('#app-api-settings-form').on('submit', updateOrderInput);
	});
})(jQuery);
