(function () {
	"use strict";

	function initializeAutomationSettings() {
		var provider = document.getElementById("provider");
		var frequency = document.getElementById("frequency");
		var apiKeyField = document.querySelector(".aab-api-key-field");
		var baseUrlField = document.querySelector(".aab-provider-url-field");
		var weekdayField = document.querySelector(".aab-weekday-field");

		if (!provider) {
			return;
		}

		function updateProviderFields() {
			var usesOllama = provider.value === "ollama";
			var usesCustomUrl = provider.value === "custom";
			if (apiKeyField) {
				apiKeyField.hidden = usesOllama;
			}
			if (baseUrlField) {
				baseUrlField.hidden = !usesOllama && !usesCustomUrl;
			}
		}

		function updateFrequencyFields() {
			if (weekdayField && frequency) {
				weekdayField.hidden = frequency.value !== "weekly";
			}
		}

		provider.addEventListener("change", updateProviderFields);
		if (frequency) {
			frequency.addEventListener("change", updateFrequencyFields);
		}
		updateProviderFields();
		updateFrequencyFields();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initializeAutomationSettings);
	} else {
		initializeAutomationSettings();
	}
})();
