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
			product: 'محصول',
			category: 'دسته‌بندی',
			brand: 'برند',
			url: 'لینک'
		};
		return Object.keys(options).map(function (key) {
			return '<option value="' + key + '">' + options[key] + '</option>';
		}).join('');
	}

	function actionOrderbyOptions() {
		var options = {
			date: 'تاریخ',
			price: 'قیمت',
			popularity: 'محبوبیت',
			rating: 'امتیاز',
			cout_sales: 'تعداد فروش'
		};
		return Object.keys(options).map(function (key) {
			return '<option value="' + key + '">' + options[key] + '</option>';
		}).join('');
	}

	function newRepeaterItem(sectionKey) {
		var index = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
		var base = optionName + '[sections][' + sectionKey + '][data][' + index + ']';
		var actionBase = base + '[action]';

		return '<div class="app-api-repeater-item" data-item-index="' + index + '">' +
			'<div class="app-api-item-topbar"><span class="dashicons dashicons-move app-api-item-drag"></span><strong>تصویر جدید</strong><button type="button" class="button-link-delete app-api-remove-item">حذف</button></div>' +
			'<div class="app-api-item-content">' +
			'<div class="app-api-media-field"><div class="app-api-image-preview"><span class="dashicons dashicons-format-image"></span><small>تصویری انتخاب نشده</small></div>' +
			'<input type="hidden" class="app-api-attachment-id" name="' + base + '[attachment_id]" value="">' +
			'<input type="hidden" class="app-api-image-url" name="' + base + '[image]" value="">' +
			'<button type="button" class="button app-api-select-image">انتخاب تصویر</button></div>' +
			'<div class="app-api-item-fields">' +
			'<div class="app-api-field-grid two-columns"><label><span>عنوان</span><input type="text" class="app-api-image-item-title" name="' + base + '[title]" value=""></label>' +
			'<label><span>زیرعنوان</span><input type="text" name="' + base + '[subtitle]" value=""></label></div>' +
			'<div class="app-api-action-editor app-api-action-fields">' +
			'<div class="app-api-field-grid four-columns">' +
			'<label><span>نوع مقصد</span><select name="' + actionBase + '[type]" class="app-api-action-type">' + actionOptions() + '</select></label>' +
			'<label class="app-api-destination-field"><span class="app-api-destination-label">شناسه مقصد</span><input type="text" dir="ltr" class="app-api-action-destination" name="' + actionBase + '[destination]" value="" inputmode="numeric" placeholder="مثلاً 350"></label>' +
			'<label><span>مرتب‌سازی</span><select name="' + actionBase + '[orderby]" class="app-api-action-orderby">' + actionOrderbyOptions() + '</select></label>' +
			'<label><span>ترتیب</span><select name="' + actionBase + '[order]"><option value="desc">نزولی</option><option value="asc">صعودی</option></select></label>' +
			'</div>' +
			'<label class="app-api-check app-api-action-sale-field" hidden><input type="checkbox" name="' + actionBase + '[on_sale]" value="1" disabled> فقط محصولات تخفیف‌دار نمایش داده شوند</label>' +
			'</div></div></div></div>';
	}

	function syncActionField($select) {
		var type = String($select.val() || 'product');
		var $container = $select.closest('.app-api-action-fields');
		var $field = $container.find('.app-api-destination-field').first();
		var $label = $container.find('.app-api-destination-label').first();
		var $input = $container.find('.app-api-action-destination').first();
		var $saleField = $container.find('.app-api-action-sale-field').first();
		var $saleInput = $saleField.find('input[type="checkbox"]').first();
		var identifierType = ['product', 'category', 'brand'].indexOf(type) !== -1;
		var saleType = String($container.attr('data-allow-sale') || '1') !== '0' && ['category', 'brand'].indexOf(type) !== -1;

		if (type === 'all') {
			$field.prop('hidden', true);
			$input.val('').prop('disabled', true);
		} else if (type === 'url') {
			$field.prop('hidden', false);
			$label.text('لینک مقصد');
			$input.prop('disabled', false).attr({ placeholder: 'https://example.com/...', inputmode: 'url' });
		} else if (identifierType) {
			$field.prop('hidden', false);
			$label.text('شناسه مقصد');
			$input.prop('disabled', false).attr({ placeholder: 'خالی = همه موارد این نوع', inputmode: 'numeric' });
		} else {
			$field.prop('hidden', true);
			$input.val('').prop('disabled', true);
		}

		if (saleType) {
			$saleField.prop('hidden', false);
			$saleInput.prop('disabled', false);
		} else {
			$saleInput.prop('checked', false).prop('disabled', true);
			$saleField.prop('hidden', true);
		}
	}

	function syncCategorySelector($select) {
		var source = String($select.val() || 'custom');
		var $container = $select.closest('[data-category-selector]');
		var $custom = $container.find('.app-api-custom-category-field').first();
		var $parent = $container.find('.app-api-parent-category-field').first();
		var $help = $container.find('.app-api-parent-category-help').first();
		var useParent = source === 'parent';

		$custom.prop('hidden', useParent);
		$custom.find(':input').prop('disabled', useParent);
		$parent.prop('hidden', !useParent);
		$parent.find(':input').prop('disabled', !useParent);
		$help.prop('hidden', !useParent);
	}

	function syncViewAll($checkbox) {
		var $settings = $checkbox.closest('.app-api-view-all-settings');
		var $fields = $settings.find('.app-api-view-all-fields').first();
		var checked = $checkbox.is(':checked');
		var panelActive = !$checkbox.prop('disabled') && !$settings.closest('.app-api-type-panel').prop('hidden');
		$fields.prop('hidden', !checked);
		$fields.find(':input').prop('disabled', !panelActive);
		if (panelActive) {
			$fields.find('.app-api-action-type').each(function () {
				syncActionField($(this));
			});
		}
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
			if (active) {
				$panel.find('.app-api-view-all-enabled').each(function () { syncViewAll($(this)); });
			}
		});
	}

	function applyType($card, type, resetLayout) {
		var defaults = (window.appApiAdmin && appApiAdmin.defaultLayouts && appApiAdmin.defaultLayouts[type]) || {};
		$card.find('.app-api-section-type-select').val(type);
		$card.find('.app-api-section-type-display').val(type);
		setTypePanelState($card, type);
		if (resetLayout) {
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
		$card.removeClass('is-disabled').prop('open', false);
		syncSectionIdentity($card);
		return { card: $card, id: id };
	}

	function accordionBody($details) {
		return $details.children('.app-api-accordion-body').first();
	}

	function closeAccordion($details, immediate) {
		if (!$details.length || !$details.prop('open')) {
			return;
		}
		var $body = accordionBody($details);
		if (!$body.length || immediate) {
			$details.prop('open', false);
			return;
		}
		$body.stop(true, true).css({ overflow: 'hidden', height: $body.outerHeight(), opacity: 1 });
		$body.animate({ height: 0, opacity: 0 }, 220, function () {
			$details.prop('open', false);
			$body.css({ overflow: '', height: '', opacity: '' });
		});
	}

	function openAccordion($details) {
		if (!$details.length || $details.prop('open')) {
			return;
		}
		$('.app-api-tab-panel.is-active details.app-api-accordion[open]').not($details).each(function () {
			closeAccordion($(this), false);
		});
		$details.prop('open', true);
		var $body = accordionBody($details);
		if (!$body.length) {
			return;
		}
		var targetHeight = $body.get(0).scrollHeight;
		$body.stop(true, true).css({ overflow: 'hidden', height: 0, opacity: 0 });
		$body.animate({ height: targetHeight, opacity: 1 }, 240, function () {
			$body.css({ overflow: '', height: '', opacity: '' });
		});
	}

	function toggleAccordion($details) {
		if ($details.prop('open')) {
			closeAccordion($details, false);
		} else {
			openAccordion($details);
		}
	}

	$(function () {
		initSortables();

		$(document).on('click', 'details.app-api-accordion > summary', function (event) {
			if ($(event.target).closest('button, a, input, select, textarea, label').length) {
				return;
			}
			event.preventDefault();
			toggleAccordion($(this).parent());
		});

		$('.app-api-section-card').each(function () {
			var $card = $(this);
			setTypePanelState($card, $card.find('.app-api-section-type-select').first().val() || $card.data('section-type'));
		});

		$('.app-api-action-type').each(function () {
			syncActionField($(this));
		});

		$('.app-api-category-source').each(function () {
			syncCategorySelector($(this));
		});

		$('.app-api-view-all-enabled').each(function () {
			syncViewAll($(this));
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
			openAccordion(built.card);
			built.card.find('.app-api-view-all-enabled').each(function () { syncViewAll($(this)); });
			built.card.find('.app-api-action-type').each(function () { syncActionField($(this)); });
			built.card.find('.app-api-category-source').each(function () { syncCategorySelector($(this)); });
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
			var $item = $(newRepeaterItem($repeater.data('section')));
			$repeater.find('.app-api-items-sortable').append($item);
			syncActionField($item.find('.app-api-action-type'));
		});
		$(document).on('click', '.app-api-remove-item', function () {
			if (window.confirm(appApiAdmin.deleteItem || 'این آیتم حذف شود؟')) {
				$(this).closest('.app-api-repeater-item').remove();
			}
		});
		$(document).on('change', '.app-api-action-type', function () {
			syncActionField($(this));
		});

		$(document).on('change', '.app-api-category-source', function () {
			syncCategorySelector($(this));
		});

		$(document).on('change', '.app-api-view-all-enabled', function () {
			syncViewAll($(this));
		});

		$(document).on('input', '.app-api-image-item-title', function () {
			var title = $(this).val() || 'آیتم بدون عنوان';
			var $item = $(this).closest('.app-api-repeater-item');
			$item.find('.app-api-item-topbar strong').text(title);
			$item.find('.app-api-action-fields').attr('data-action-title', $(this).val() || '');
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
