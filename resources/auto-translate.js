import './auto-translate.css';

(function () {
	'use strict';

	var config = window.hdPllAi || {};
	var JOB_PATH = '/hd/v1/pll/ai/jobs';

	/**
	 * Unified API fetch. Method is POST when data is provided, GET otherwise.
	 */
	function apiFetch(path, data) {
		var opts = {
			path: path,
			method: data ? 'POST' : 'GET',
			headers: { 'X-WP-Nonce': config.nonce },
		};

		if (data) {
			opts.data = data;
		}

		return window.wp.apiFetch(opts);
	}

	function errorMessage(error) {
		return (error && (error.message || error.code)) || 'Translation request failed.';
	}

	/**
	 * Normalize any API response envelope into a flat { targetId, links, job, preview }.
	 * Server jobResponsePayload() guarantees target_id + links at the top level.
	 */
	function unwrap(response) {
		var data = response && response.data ? response.data : response || {};

		return {
			targetId: parseInt(data.target_id || 0, 10),
			links: data.links || {},
			job: data.job || {},
			preview: data.preview || {},
		};
	}

	/**
	 * Fallback link builder if the server didn't return links (edge case).
	 */
	function resolveLinks(res) {
		var links = res.links;
		var tid = res.targetId;

		return {
			edit: links.edit || (tid && config.adminUrl ? config.adminUrl + 'post.php?post=' + tid + '&action=edit' : ''),
			view: links.view || '',
		};
	}

	/**
	 * DOM element factory. Reduces createElement boilerplate.
	 */
	function makeEl(tag, className, text) {
		var el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		if (text) {
			el.textContent = text;
		}

		return el;
	}

	function actionLink(href, text) {
		var a = makeEl('a', 'hd-pll-ai-edit', text);
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';

		return a;
	}

	/**
	 * Show inline toast near the link.
	 */
	function showToast(anchor, message, type) {
		var parent = anchor.closest('[class*="hd_pll_ai_"]') || anchor.parentNode;
		var toast = makeEl('span', 'hd-pll-ai-toast hd-pll-ai-toast--' + type, message);
		parent.appendChild(toast);

		setTimeout(function () {
			toast.classList.add('hd-pll-ai-toast--fade');
			setTimeout(function () {
				toast.remove();
			}, 400);
		}, 4000);
	}

	/**
	 * Show a persistent WP admin notice with Edit + View + Reload actions.
	 */
	function showAdminNotice(lang, links) {
		var wrap = document.querySelector('.wrap');
		var anchor = wrap && (wrap.querySelector('.page-title-action') || wrap.querySelector('h1, .wp-heading-inline'));
		var notice = makeEl('div', 'notice notice-success hd-pll-ai-notice');
		var p = document.createElement('p');
		var reload = makeEl('a', 'hd-pll-ai-notice-reload', 'Reload page');

		p.appendChild(makeEl('strong', '', lang));
		p.appendChild(document.createTextNode(' translation created as draft. '));

		if (links.edit) {
			p.appendChild(actionLink(links.edit, 'Edit draft'));
			p.appendChild(document.createTextNode(' | '));
		}

		if (links.view) {
			p.appendChild(actionLink(links.view, 'View draft'));
			p.appendChild(document.createTextNode(' | '));
		}

		reload.href = '#';
		reload.addEventListener('click', function (e) {
			e.preventDefault();
			window.location.reload();
		});
		p.appendChild(reload);
		notice.appendChild(p);

		if (anchor && anchor.nextSibling) {
			anchor.parentNode.insertBefore(notice, anchor.nextSibling);
		} else if (wrap) {
			wrap.appendChild(notice);
		}
	}

	/**
	 * Mark the AI action as completed without replacing WP row-action nodes.
	 */
	function markCompleted(link, links) {
		var lang = link.dataset.originalText || link.dataset.targetLang.toUpperCase();

		link.classList.remove('is-loading');
		link.classList.add('is-completed', 'hd-pll-ai-done');
		link.setAttribute('aria-disabled', 'true');
		link.textContent = '';
		link.appendChild(makeEl('span', 'dashicons dashicons-yes-alt'));
		link.appendChild(document.createTextNode(' ' + lang));

		if (links.edit) {
			var extra = makeEl('span', 'hd-pll-ai-result-links');
			extra.appendChild(document.createTextNode(' '));
			extra.appendChild(actionLink(links.edit, 'Edit'));
			link.insertAdjacentElement('afterend', extra);
		}
	}

	// ── Row action click handler ──
	document.addEventListener('click', function (event) {
		var link = event.target.closest('.hd-pll-ai-action');
		if (!link || !config.nonce || !window.wp || !window.wp.apiFetch) {
			return;
		}

		event.preventDefault();

		if (link.classList.contains('is-loading') || link.classList.contains('is-completed')) {
			return;
		}

		link.classList.add('is-loading');
		link.dataset.originalText = link.textContent;
		link.textContent = '';
		link.appendChild(makeEl('span', 'hd-pll-ai-spinner'));

		apiFetch(JOB_PATH, {
			type: link.dataset.type,
			source_id: parseInt(link.dataset.sourceId, 10),
			target_lang: link.dataset.targetLang,
			options: { commit: true, status: 'draft' },
		})
			.then(function (response) {
				var res = unwrap(response);
				if (!res.job.id) {
					throw new Error('Translation job was not created.');
				}

				return apiFetch(JOB_PATH + '/' + res.job.id + '/run', {});
			})
			.then(function (response) {
				var res = unwrap(response);
				var links = resolveLinks(res);

				markCompleted(link, links);
				showAdminNotice(link.dataset.targetLang.toUpperCase(), links);
			})
			.catch(function (error) {
				link.textContent = link.dataset.originalText || link.dataset.targetLang.toUpperCase();
				link.classList.remove('is-loading');
				showToast(link, errorMessage(error), 'error');
			});
	});

	// ── Classic editor field fill helper ──
	function fillField(id, value) {
		var el = document.getElementById(id);
		if (value && el && !el.value) {
			el.value = value;
		}
	}

	// ── Block editor field fill helper ──
	function editBlockField(editor, attr, value) {
		if (!value) {
			return;
		}

		var current = 'content' === attr ? editor.select('core/editor').getEditedPostContent() : editor.select('core/editor').getEditedPostAttribute(attr);

		if (!current) {
			var payload = {};
			payload[attr] = value;
			editor.dispatch('core/editor').editPost(payload);
		}
	}

	// ── Editor assist (new translation page) ──
	document.addEventListener('DOMContentLoaded', function () {
		var params;

		if (!config.editorAssist || !window.wp || !window.wp.apiFetch) {
			return;
		}

		params = new URLSearchParams(window.location.search);
		if (!params.get('from_post') || !params.get('new_lang')) {
			return;
		}

		apiFetch('/hd/v1/pll/ai/translate/post', {
			source_id: parseInt(params.get('from_post'), 10),
			target_lang: params.get('new_lang'),
			options: { commit: false },
		})
			.then(function (response) {
				var res = unwrap(response);
				var fields = res.preview && res.preview.fields;
				var editor = window.wp.data;

				if (!fields) {
					return;
				}

				fillField('title', fields.post_title);
				fillField('content', fields.post_content);
				fillField('excerpt', fields.post_excerpt);

				if (editor && editor.dispatch && editor.select) {
					editBlockField(editor, 'title', fields.post_title);
					editBlockField(editor, 'content', fields.post_content);
					editBlockField(editor, 'excerpt', fields.post_excerpt);
				}
			})
			.catch(function (error) {
				/* eslint-disable-next-line no-console */
				console.warn('[HD PLL AI]', errorMessage(error));
			});
	});
})();
