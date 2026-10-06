(function () {
	'use strict';

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (character) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[character];
		});
	}

	function escapeAttr(value) {
		return escapeHtml(value).replace(/`/g, '&#096;');
	}

	function fieldHtml(name, label, type, required, extra) {
		var attrs = required ? ' required' : '';
		var inputType = type || 'text';

		if (inputType === 'textarea') {
			return '<div class="ys-booking-field"><label>' + escapeHtml(label) + '</label><textarea name="' + escapeAttr(name) + '[]" rows="2"' + attrs + '></textarea></div>';
		}

		return '<div class="ys-booking-field"><label>' + escapeHtml(label) + '</label><input name="' + escapeAttr(name) + '[]" type="' + escapeAttr(inputType) + '"' + attrs + (extra || '') + '></div>';
	}

	function customFieldHtml(field, index) {
		var key = String(field.key || '').replace(/[^a-z0-9_]/g, '');
		var label = escapeHtml(field.label || '');
		var required = field.required ? ' required' : '';
		var type = ['textarea', 'email', 'tel', 'number', 'date', 'checkbox'].indexOf(field.type) !== -1 ? field.type : 'text';
		var name = 'ys_custom_participant[' + key + '][' + index + ']';

		if (!key || !label) {
			return '';
		}

		if (type === 'checkbox') {
			return '<div class="ys-booking-field"><label><input name="' + escapeAttr(name) + '" type="checkbox" value="1"' + required + '> ' + label + '</label></div>';
		}

		if (type === 'textarea') {
			return '<div class="ys-booking-field"><label>' + label + '</label><textarea name="' + escapeAttr(name) + '" rows="2"' + required + '></textarea></div>';
		}

		return '<div class="ys-booking-field"><label>' + label + '</label><input name="' + escapeAttr(name) + '" type="' + escapeAttr(type) + '"' + (type === 'number' ? ' step="any"' : '') + required + '></div>';
	}

	function emergencyContactHtml(index, hasParentContact) {
		var id = 'ys_participant_emergency_contact_' + index;
		var html = '';

		if (hasParentContact) {
			html += '<div class="ys-booking-field ys-emergency-same-field">' +
				'<label><input type="checkbox" name="ys_participant_emergency_same_as_parent[' + index + ']" value="1" data-ys-participant-emergency-same aria-controls="' + id + '"> Emergency contact details are the same as the parent contact details</label>' +
				'</div>';
		}

		html += '<div class="ys-booking-field" data-ys-participant-emergency-field>' +
			'<label for="' + id + '">Emergency contact</label>' +
			'<input id="' + id + '" name="ys_participant_emergency_contact[' + index + ']" data-ys-participant-emergency-input required>' +
			'</div>';

		return html;
	}

	function renderParticipants(form) {
		var quantity = form.querySelector('[name="ys_quantity"]');
		var wrap = form.querySelector('[data-participants]');
		var fields = {};
		var customFields = [];
		var hasParentContact = form.getAttribute('data-has-parent-contact') === '1';

		try {
			fields = JSON.parse(form.getAttribute('data-participant-fields') || '{}');
		} catch (error) {
			fields = {};
		}

		try {
			customFields = JSON.parse(form.getAttribute('data-participant-custom-fields') || '[]');
		} catch (error) {
			customFields = [];
		}

		if (!Array.isArray(customFields)) {
			customFields = [];
		}

		if (!quantity || !wrap) {
			return;
		}

		var count = Math.max(1, Math.min(parseInt(quantity.value || '1', 10), parseInt(quantity.getAttribute('max') || '20', 10)));
		var html = '';
		var hasParticipantFields = fields.participant_name || fields.participant_age || fields.parent_email || fields.parent_phone || fields.emergency_contact || fields.dietary || fields.accessibility || customFields.length > 0;

		if (!hasParticipantFields) {
			wrap.innerHTML = '';
			return;
		}

		for (var index = 0; index < count; index += 1) {
			var inputs = '';

			if (fields.participant_name) {
				inputs += fieldHtml('ys_participant_name', 'Name', 'text', true);
			}

			if (fields.participant_age) {
				inputs += fieldHtml('ys_participant_age', 'Age', 'number', true, ' min="0" max="120"');
			}

			if (fields.parent_email) {
				inputs += fieldHtml('ys_participant_parent_email', 'Parent email', 'email', true);
			}

			if (fields.parent_phone) {
				inputs += fieldHtml('ys_participant_parent_phone', 'Parent tel number', 'tel', true);
			}

			if (fields.emergency_contact) {
				inputs += emergencyContactHtml(index, hasParentContact);
			}

			if (fields.dietary) {
				inputs += fieldHtml('ys_participant_dietary', 'Dietary requirements', 'textarea', false);
			}

			if (fields.accessibility) {
				inputs += fieldHtml('ys_participant_accessibility', 'Please tell us about any Accessibility, Medical or support accommodations required', 'textarea', false);
			}

			customFields.forEach(function (field) {
				inputs += customFieldHtml(field, index);
			});

			html += '<div class="ys-participant"><h3>Participant ' + (index + 1) + '</h3><div class="ys-booking-grid">' + inputs + '</div></div>';
		}

		wrap.innerHTML = html;
		setupParticipantEmergencyContacts(form);
	}

	function setupParticipantEmergencyContacts(form) {
		form.querySelectorAll('.ys-participant').forEach(function (participant) {
			var same = participant.querySelector('[data-ys-participant-emergency-same]');
			var field = participant.querySelector('[data-ys-participant-emergency-field]');
			var input = participant.querySelector('[data-ys-participant-emergency-input]');

			if (!field || !input) {
				return;
			}

			function refresh() {
				var useParentContact = same && same.checked;

				input.required = !useParentContact;
				input.disabled = !!useParentContact;
				field.style.display = useParentContact ? 'none' : '';

				if (useParentContact) {
					input.value = '';
				}
			}

			if (same) {
				same.addEventListener('change', refresh);
			}

			refresh();
		});
	}

	function parsePositiveInt(value, fallback) {
		var parsed = parseInt(value, 10);

		return isFinite(parsed) && parsed > 0 ? parsed : fallback;
	}

	function setQuantityMax(form, remaining) {
		var quantity = form.querySelector('[data-ys-quantity], [name="ys_quantity"]');
		var parsedRemaining = parseInt(remaining, 10);
		var safeRemaining = isFinite(parsedRemaining) && parsedRemaining >= 0 ? parsedRemaining : 20;
		var nextMax = Math.max(1, Math.min(20, safeRemaining));
		var changed = false;
		var current;

		if (!quantity) {
			return false;
		}

		if (quantity.getAttribute('max') !== String(nextMax)) {
			quantity.setAttribute('max', String(nextMax));
		}

		current = parsePositiveInt(quantity.value, 1);

		if (current > nextMax) {
			quantity.value = String(nextMax);
			changed = true;
		} else if (current < 1) {
			quantity.value = '1';
			changed = true;
		}

		return changed;
	}

	function setAvailabilityDisabled(form, disabled) {
		form.classList.toggle('ys-booking-form-is-unavailable', !!disabled);
		form.setAttribute('aria-disabled', disabled ? 'true' : 'false');

		Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea, button'), function (control) {
			if (control.type === 'hidden' || control.name === 'ys_booking_nonce') {
				return;
			}

			if (disabled) {
				if (!control.disabled) {
					control.setAttribute('data-ys-disabled-by-availability', '1');
				}

				control.disabled = true;
				return;
			}

			if (control.getAttribute('data-ys-disabled-by-availability') === '1') {
				control.disabled = false;
				control.removeAttribute('data-ys-disabled-by-availability');
			}
		});

		if (!disabled) {
			setupParticipantEmergencyContacts(form);
		}
	}

	function updateNonce(form, nonce) {
		var field = form.querySelector('[name="ys_booking_nonce"]');

		if (field && nonce) {
			field.value = nonce;
		}
	}

	function updateRemainingDisplay(form, eventAvailability) {
		var box = form.closest('.ys-booking-box');
		var remaining = box ? box.querySelector('[data-ys-remaining], .ys-remaining') : null;

		if (!remaining || !eventAvailability) {
			return;
		}

		remaining.textContent = eventAvailability.remaining_label || '';
		remaining.classList.toggle('ys-full', !eventAvailability.available);
	}

	function updateSlotOptions(form, slots) {
		var select = form.querySelector('[data-ys-slot-select], [name="ys_slot"]');
		var byKey = {};
		var hasAvailable = false;

		if (!select || !Array.isArray(slots)) {
			return false;
		}

		slots.forEach(function (slot) {
			if (!slot || !slot.key) {
				return;
			}

			byKey[String(slot.key)] = slot;
		});

		Array.prototype.forEach.call(select.options, function (option) {
			var key = option.getAttribute('data-ys-slot-key') || option.value;
			var slot;

			if (!key || !byKey[key]) {
				return;
			}

			slot = byKey[key];
			option.textContent = slot.option_label || option.textContent;
			option.disabled = !slot.available;
			option.setAttribute('data-ys-remaining', String(parsePositiveInt(slot.remaining, 0)));

			if (slot.available) {
				hasAvailable = true;
			}
		});

		if (select.selectedIndex >= 0 && select.options[select.selectedIndex].disabled) {
			select.value = '';
		}

		return hasAvailable;
	}

	function syncTimedQuantityMax(form) {
		var select = form.querySelector('[data-ys-slot-select], [name="ys_slot"]');
		var option;

		if (!select || !select.selectedIndex || select.selectedIndex < 0) {
			return setQuantityMax(form, 20);
		}

		option = select.options[select.selectedIndex];

		if (!option || !option.getAttribute('data-ys-slot-key')) {
			return setQuantityMax(form, 20);
		}

		return setQuantityMax(form, option.getAttribute('data-ys-remaining') || '20');
	}

	function availabilityUrl(form) {
		var url = form.getAttribute('data-availability-url');
		var eventId;

		if (url) {
			return url;
		}

		eventId = form.querySelector('[name="ys_event_id"]');

		if (!eventId || !eventId.value || !window.ysEventsPublic || !window.ysEventsPublic.ajaxUrl) {
			return '';
		}

		return window.ysEventsPublic.ajaxUrl + '?action=ys_event_availability&event_id=' + encodeURIComponent(eventId.value);
	}

	function applyAvailability(form, data) {
		var type;
		var quantityChanged = false;
		var unavailable = false;
		var hasAvailableSlots = false;

		if (!data || typeof data !== 'object') {
			return;
		}

		updateNonce(form, data.nonce);

		type = data.type || form.getAttribute('data-event-type');

		if (type === 'timed') {
			hasAvailableSlots = updateSlotOptions(form, data.slots || []);
			quantityChanged = syncTimedQuantityMax(form);
			unavailable = !data.bookings_open || !hasAvailableSlots;
		} else if (data.event) {
			updateRemainingDisplay(form, data.event);
			quantityChanged = setQuantityMax(form, data.event.remaining);
			unavailable = !data.bookings_open || !data.event.available;
		}

		setAvailabilityDisabled(form, unavailable);

		if (quantityChanged) {
			renderParticipants(form);
			setAvailabilityDisabled(form, unavailable);
		}
	}

	function refreshAvailability(form) {
		var url = availabilityUrl(form);

		if (!url || typeof window.fetch !== 'function') {
			return;
		}

		form.classList.add('ys-booking-form-is-refreshing');

		window.fetch(url, {
			cache: 'no-store',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json'
			}
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (!response || !response.success) {
				return;
			}

			applyAvailability(form, response.data);
		}).catch(function () {
			// Keep the server-rendered fallback and final submission check if refresh fails.
		}).then(function () {
			form.classList.remove('ys-booking-form-is-refreshing');
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-ys-booking-form], .ys-booking-form').forEach(function (form) {
			var quantity = form.querySelector('[data-ys-quantity], [name="ys_quantity"]');
			var slot = form.querySelector('[data-ys-slot-select], [name="ys_slot"]');

			if (quantity) {
				quantity.addEventListener('input', function () {
					renderParticipants(form);
				});
			}

			if (slot) {
				slot.addEventListener('change', function () {
					if (syncTimedQuantityMax(form)) {
						renderParticipants(form);
					}
				});
			}

			renderParticipants(form);
			refreshAvailability(form);
		});
	});
}());
