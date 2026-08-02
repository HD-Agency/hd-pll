import './options-translate.css';

/**
 * PLL Options Translate — Slide-over JS
 *
 * Admin-only JS for ACF Options Pages translation panel.
 * Handles slide-over open/close, AJAX form load/save/copy.
 *
 * Dependencies: jQuery, acf-input
 * Localized data: pllAcfOptions (via wp_localize_script)
 */
(function($) {
	'use strict';

	if (typeof pllAcfOptions === 'undefined') return;

	// Lazy DOM refs — slide-over shell is rendered in admin_footer,
	// which may fire AFTER this script executes.
	var $slideover, $content, $title, $spinner, $removeBtn;
	var tmplLoading, tmplError;

	function initDom() {
		if ($slideover) return;
		$slideover  = $('#pll-options-slideover');
		$content    = $slideover.find('.pll-slideover__content');
		$title      = $slideover.find('.pll-slideover__title');
		$spinner    = $slideover.find('.pll-slideover__spinner');
		$removeBtn  = $slideover.find('.pll-remove-translation-btn');
		tmplLoading = wp.template('pll-options-loading');
		tmplError   = wp.template('pll-options-error');
	}

	var currentLang     = '';
	var currentPostId   = '';
	var currentMenuSlug = '';

	// Detect menu_slug from URL.
	var menuSlugMatch = window.location.href.match(/[?&]page=([^&#]+)/);
	currentMenuSlug   = menuSlugMatch ? menuSlugMatch[1] : '';

	// ── Panel controls ──

	function openPanel(lang, langName, postId) {
		initDom();
		currentLang   = lang;
		currentPostId = postId;

		$title.text(pllAcfOptions.i18n.translateTo.replace('%s', langName));
		$removeBtn.toggle(hasTranslationData());
		$slideover.addClass('is-open');
		$('body').css('overflow', 'hidden');

		loadForm();
	}

	function getCurrentRow() {
		return $('.pll-options-lang-row[data-lang="' + currentLang + '"]');
	}

	function hasTranslationData() {
		return getCurrentRow().attr('data-has-data') === '1';
	}

	function updateTranslationStatus(hasData) {
		var $row = getCurrentRow();
		var $dot = $row.find('.pll-status-dot');

		$row.attr('data-has-data', hasData ? '1' : '0');
		$dot
			.css('background', hasData ? '#00a32a' : '#dcdcde')
			.attr('title', hasData
				? (pllAcfOptions.i18n.hasTranslation || 'Has translation')
				: (pllAcfOptions.i18n.noTranslation || 'No translation')
			);
	}

	function closePanel() {
		initDom();
		$slideover.removeClass('is-open');
		setTimeout(function() {
			$content.empty();
		}, 300);
		$('body').css('overflow', '');
		currentLang = '';
	}

	// ── AJAX: Load form ──

	function loadForm() {
		$content.html(tmplLoading({}));

		$.post(pllAcfOptions.ajaxUrl, {
			action:    'hd_pll_acf_options_form',
			nonce:     pllAcfOptions.nonce,
			post_id:   currentPostId,
			lang:      currentLang,
			menu_slug: currentMenuSlug
		}).done(function(res) {
			if (res.success && res.data.html) {
				$content.html(res.data.html);
				if (typeof acf !== 'undefined') {
					acf.doAction('ready', $content);
				}
			} else {
				$content.html(tmplError({ message: res.data?.message || pllAcfOptions.i18n.error }));
			}
		}).fail(function() {
			$content.html(tmplError({ message: pllAcfOptions.i18n.error }));
		});
	}

	// ── AJAX: Save form ──

	function saveForm() {
		var $saveBtn = $slideover.find('.pll-slideover__save');
		var origText = $saveBtn.text();
		$saveBtn.prop('disabled', true).text(pllAcfOptions.i18n.saving);

		var formData = $content.find(':input').serializeArray();
		formData.push({ name: 'action', value: 'hd_pll_acf_options_save' });
		formData.push({ name: 'nonce', value: pllAcfOptions.nonce });
		formData.push({ name: 'post_id', value: currentPostId });
		formData.push({ name: 'lang', value: currentLang });
		formData.push({ name: 'menu_slug', value: currentMenuSlug });

		$.post(pllAcfOptions.ajaxUrl, formData).done(function(res) {
			if (res.success) {
				$saveBtn.text(pllAcfOptions.i18n.saved);
				updateTranslationStatus(true);
				setTimeout(closePanel, 800);
			} else {
				alert(res.data?.message || pllAcfOptions.i18n.error);
			}
		}).fail(function() {
			alert(pllAcfOptions.i18n.error);
		}).always(function() {
			$saveBtn.prop('disabled', false).text(origText);
		});
	}

	// ── AJAX: Copy from default ──

	function copyFromDefault() {
		var $copyBtn = $slideover.find('.pll-copy-default-btn');
		var origHtml = $copyBtn.html();
		$spinner.addClass('is-active');
		$copyBtn.prop('disabled', true).text(pllAcfOptions.i18n.copying);

		$.post(pllAcfOptions.ajaxUrl, {
			action:    'hd_pll_acf_options_copy',
			nonce:     pllAcfOptions.nonce,
			post_id:   currentPostId,
			lang:      currentLang,
			menu_slug: currentMenuSlug
		}).done(function(res) {
			if (res.success) {
				$copyBtn.text(pllAcfOptions.i18n.copied);
				updateTranslationStatus(true);
				$removeBtn.show();
				setTimeout(function() {
					loadForm();
					$copyBtn.html(origHtml);
				}, 500);
			} else {
				alert(res.data?.message || pllAcfOptions.i18n.error);
				$copyBtn.html(origHtml);
			}
		}).fail(function() {
			alert(pllAcfOptions.i18n.error);
			$copyBtn.html(origHtml);
		}).always(function() {
			$spinner.removeClass('is-active');
			$copyBtn.prop('disabled', false);
		});
	}

	// AJAX: Remove translation.

	function removeTranslation() {
		if (!confirm(pllAcfOptions.i18n.confirmRemove || 'Remove this translation? Fields will fall back to the default language.')) {
			return;
		}

		var origHtml = $removeBtn.html();
		$spinner.addClass('is-active');
		$removeBtn.prop('disabled', true).text(pllAcfOptions.i18n.removing || 'Removing...');

		$.post(pllAcfOptions.ajaxUrl, {
			action:    'hd_pll_acf_options_remove',
			nonce:     pllAcfOptions.nonce,
			post_id:   currentPostId,
			lang:      currentLang,
			menu_slug: currentMenuSlug
		}).done(function(res) {
			if (res.success) {
				$removeBtn.text(pllAcfOptions.i18n.removed || 'Removed!');
				updateTranslationStatus(false);
				setTimeout(function() {
					closePanel();
					$removeBtn.html(origHtml).hide();
				}, 500);
			} else {
				alert(res.data?.message || pllAcfOptions.i18n.error);
				$removeBtn.html(origHtml);
			}
		}).fail(function() {
			alert(pllAcfOptions.i18n.error);
			$removeBtn.html(origHtml);
		}).always(function() {
			$spinner.removeClass('is-active');
			$removeBtn.prop('disabled', false);
		});
	}

	// ── Event bindings (all delegated — DOM may not exist yet) ──

	$(document).on('click', '.pll-translate-btn', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var $btn = $(this);
		var $row = $btn.closest('.pll-options-lang-row');
		openPanel($btn.data('lang'), $btn.data('lang-name'), $row.data('post-id'));
	});

	$(document).on('click', '#pll-options-slideover .pll-slideover__close, #pll-options-slideover .pll-slideover__cancel', closePanel);
	$(document).on('click', '#pll-options-slideover .pll-slideover__backdrop', closePanel);
	$(document).on('click', '#pll-options-slideover .pll-slideover__save', saveForm);
	$(document).on('click', '#pll-options-slideover .pll-copy-default-btn', copyFromDefault);
	$(document).on('click', '#pll-options-slideover .pll-remove-translation-btn', removeTranslation);

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape' && $slideover && $slideover.hasClass('is-open')) {
			closePanel();
		}
	});

})(jQuery);
