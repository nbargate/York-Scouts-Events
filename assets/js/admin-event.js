(function () {
	'use strict';

	function setControlsDisabled(container, disabled) {
		if (!container) {
			return;
		}

		container.querySelectorAll('input, select, textarea, button').forEach(function (control) {
			control.disabled = disabled;
		});
	}

	function setupSettingsBox() {
		var wrap = document.getElementById('ys-booking-settings-wrap');
		var enabled = document.querySelector('input[name="ys_bookings_enabled"]');
		var type = document.getElementById('ys_event_type');
		var timed = document.getElementById('ys-timed-fields');
		var general = document.querySelector('.ys-general-field');
		var slotMode = document.getElementById('ys_slot_mode');
		var autoSlotFields = document.getElementById('ys-auto-slot-fields');
		var customSlotFields = document.getElementById('ys-custom-slot-fields');

		if (!wrap || !enabled || !type) {
			return;
		}

		function refresh() {
			var on = enabled.checked;
			var isTimed = type.value === 'timed';

			wrap.style.display = on ? 'block' : 'none';

			if (general) {
				general.style.display = on && !isTimed ? 'block' : 'none';
			}

			if (timed) {
				timed.style.display = on && isTimed ? 'block' : 'none';
			}

			setControlsDisabled(wrap, !on);

			if (on) {
				type.disabled = false;
				setControlsDisabled(general, isTimed);
				setControlsDisabled(timed, !isTimed);

				if (isTimed && slotMode) {
					slotMode.disabled = false;
					setControlsDisabled(autoSlotFields, slotMode.value === 'custom');
					setControlsDisabled(customSlotFields, slotMode.value !== 'custom');
					if (autoSlotFields) {
						autoSlotFields.style.display = slotMode.value === 'custom' ? 'none' : 'block';
					}
					if (customSlotFields) {
						customSlotFields.style.display = slotMode.value === 'custom' ? 'block' : 'none';
					}
				}
			}
		}

		type.addEventListener('change', refresh);
		enabled.addEventListener('change', refresh);
		if (slotMode) {
			slotMode.addEventListener('change', refresh);
		}
		refresh();
	}

	function setupCustomSlots() {
		var body = document.getElementById('ys-custom-slots-body');
		var addButton = document.getElementById('ys-add-custom-slot');

		if (!body || !addButton) {
			return;
		}

		function defaultCapacity() {
			var input = document.getElementById('ys_slot_capacity');
			return input && input.value ? input.value : '1';
		}

		function addRow() {
			var row = document.createElement('tr');
			row.innerHTML = '<td><input type="time" name="ys_custom_slot_start[]" value=""></td>' +
				'<td><input type="time" name="ys_custom_slot_end[]" value=""></td>' +
				'<td><input type="number" min="1" step="1" name="ys_custom_slot_capacity[]" value="' + defaultCapacity() + '"></td>' +
				'<td><button type="button" class="button-link-delete ys-remove-slot">Remove</button></td>';
			body.appendChild(row);
		}

		addButton.addEventListener('click', addRow);
		body.addEventListener('click', function (event) {
			if (!event.target.classList.contains('ys-remove-slot')) {
				return;
			}

			event.preventDefault();
			event.target.closest('tr').remove();
		});
	}

	function setupCustomFields() {
		var body = document.getElementById('ys-custom-fields-body');
		var addButton = document.getElementById('ys-add-custom-field');

		if (!body || !addButton) {
			return;
		}

		function addRow() {
			var row = document.createElement('tr');
			row.innerHTML = '<td>' +
				'<input type="hidden" name="ys_custom_field_key[]" value="">' +
				'<input type="text" name="ys_custom_field_label[]" value="" placeholder="e.g. Swimming ability">' +
				'</td>' +
				'<td><select name="ys_custom_field_scope[]">' +
				'<option value="booking">Booking details</option>' +
				'<option value="participant">Each participant</option>' +
				'</select></td>' +
				'<td><select name="ys_custom_field_type[]">' +
				'<option value="text">Short text</option>' +
				'<option value="textarea">Long text</option>' +
				'<option value="email">Email address</option>' +
				'<option value="tel">Phone number</option>' +
				'<option value="number">Number</option>' +
				'<option value="date">Date</option>' +
				'<option value="checkbox">Checkbox</option>' +
				'</select></td>' +
				'<td><select name="ys_custom_field_required[]">' +
				'<option value="0">Optional</option>' +
				'<option value="1">Required</option>' +
				'</select></td>' +
				'<td><button type="button" class="button-link-delete ys-remove-custom-field">Remove</button></td>';
			body.appendChild(row);
		}

		addButton.addEventListener('click', addRow);
		body.addEventListener('click', function (event) {
			if (!event.target.classList.contains('ys-remove-custom-field')) {
				return;
			}

			event.preventDefault();
			event.target.closest('tr').remove();
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		setupSettingsBox();
		setupCustomSlots();
		setupCustomFields();
	});
}());
